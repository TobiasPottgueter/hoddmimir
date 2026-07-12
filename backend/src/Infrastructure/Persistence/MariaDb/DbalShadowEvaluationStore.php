<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Scheduler\Shadow\ShadowDecision;
use App\Application\Scheduler\Shadow\ShadowEvaluationBatch;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflict;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflictCode;
use App\Application\Scheduler\Shadow\ShadowEvaluationPersistenceResult;
use App\Application\Scheduler\Shadow\ShadowEvaluationStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalShadowEvaluationStore implements ShadowEvaluationStore
{
    private const string SCHEDULE_NAME = 'inventory';

    public function __construct(private Connection $connection)
    {
    }

    public function persist(
        CollectorLease $lease,
        ShadowEvaluationBatch $batch,
    ): ShadowEvaluationPersistenceResult {
        if (!hash_equals($lease->token->binary(), $batch->cycleToken->binary())) {
            throw new ShadowEvaluationConflict(
                ShadowEvaluationConflictCode::CycleTokenMismatch,
                'The shadow evaluation does not belong to the active collector cycle.',
            );
        }

        return $this->connection->transactional(function (Connection $connection) use ($lease, $batch): ShadowEvaluationPersistenceResult {
            $this->assertFence($connection, $lease, true);
            $payloadHash = $batch->contentHash();
            $decisionCount = count($batch->decisions);
            $gateCount = $batch->gateCount();
            $existing = $connection->fetchAssociative(
                <<<'SQL'
                    SELECT * FROM scheduler_evaluation_runs
                    WHERE cycle_token = :cycle_token OR id = :id
                    FOR UPDATE
                    SQL,
                [
                    'cycle_token' => $batch->cycleToken->binary(),
                    'id' => $batch->runId->binary(),
                ],
            );

            if (false !== $existing) {
                $result = $this->replayResult(
                    $existing,
                    $lease,
                    $batch,
                    $payloadHash,
                    $decisionCount,
                    $gateCount,
                );
                $this->assertFence($connection, $lease, false);

                return $result;
            }

            $persistedAt = $this->databaseNow($connection);
            if ($batch->finishedAt > $persistedAt) {
                throw new ShadowEvaluationConflict(
                    ShadowEvaluationConflictCode::PayloadMismatch,
                    'A shadow evaluation cannot finish after the persistence clock.',
                );
            }

            $connection->insert('scheduler_evaluation_runs', [
                'id' => $batch->runId->binary(),
                'cycle_token' => $batch->cycleToken->binary(),
                'collector_fencing_token' => $lease->fencingToken,
                'evaluator_version' => $batch->evaluatorVersion,
                'payload_hash' => $payloadHash,
                'decision_count' => $decisionCount,
                'gate_count' => $gateCount,
                'started_at' => $this->format($batch->startedAt),
                'completed_at' => $this->format($batch->finishedAt),
                'persisted_at' => $this->format($persistedAt),
            ]);

            foreach ($batch->decisions as $offset => $decision) {
                $this->insertDecision($connection, $batch, $decision, $offset + 1);
            }

            $this->assertFence($connection, $lease, false);

            return ShadowEvaluationPersistenceResult::Persisted;
        });
    }

    /** @param array<string, mixed> $existing */
    private function replayResult(
        array $existing,
        CollectorLease $lease,
        ShadowEvaluationBatch $batch,
        string $payloadHash,
        int $decisionCount,
        int $gateCount,
    ): ShadowEvaluationPersistenceResult {
        $sameRun = is_string($existing['id'] ?? null)
            && hash_equals($batch->runId->binary(), $existing['id']);
        $sameCycle = is_string($existing['cycle_token'] ?? null)
            && hash_equals($batch->cycleToken->binary(), $existing['cycle_token']);

        if (!$sameRun) {
            throw new ShadowEvaluationConflict(
                ShadowEvaluationConflictCode::CycleAlreadyEvaluated,
                'The collector cycle already has another shadow evaluation.',
            );
        }
        if (!$sameCycle) {
            throw new ShadowEvaluationConflict(
                ShadowEvaluationConflictCode::RunIdReused,
                'The shadow evaluation run ID belongs to another collector cycle.',
            );
        }

        if ($this->integer($existing, 'collector_fencing_token') !== $lease->fencingToken
            || $this->integer($existing, 'evaluator_version') !== $batch->evaluatorVersion
            || !is_string($existing['payload_hash'] ?? null)
            || !hash_equals($payloadHash, $existing['payload_hash'])
            || $this->integer($existing, 'decision_count') !== $decisionCount
            || $this->integer($existing, 'gate_count') !== $gateCount
            || ($existing['started_at'] ?? null) !== $this->format($batch->startedAt)
            || ($existing['completed_at'] ?? null) !== $this->format($batch->finishedAt)) {
            throw new ShadowEvaluationConflict(
                ShadowEvaluationConflictCode::PayloadMismatch,
                'The repeated shadow evaluation payload differs from the committed payload.',
            );
        }

        return ShadowEvaluationPersistenceResult::AlreadyPersisted;
    }

    private function insertDecision(
        Connection $connection,
        ShadowEvaluationBatch $batch,
        ShadowDecision $decision,
        int $ordinal,
    ): void {
        $placement = $decision->placement;
        $policy = $decision->policy;
        $target = $decision->target;
        $connection->insert('scheduler_decisions', [
            'id' => $decision->id->binary(),
            'evaluation_run_id' => $batch->runId->binary(),
            'decision_ordinal' => $ordinal,
            'connection_id' => $decision->connectionId->binary(),
            'cluster_id' => $decision->clusterId->binary(),
            'guest_id' => $decision->guestId->binary(),
            'node_id' => $placement?->nodeId->binary(),
            'placement_revision' => $placement?->revision,
            'placement_observed_at' => null === $placement ? null : $this->format($placement->observedAt),
            'outcome' => $decision->outcome->value,
            'reason' => $decision->reasonPriority?->reason->value,
            'priority' => $decision->reasonPriority?->priority->value,
            'policy_id' => $policy?->policyId->binary(),
            'policy_revision' => $policy?->revision,
            'policy_snapshot_hash' => $policy?->snapshotHash(),
            'target_id' => $target?->targetId->binary(),
            'target_revision' => $target?->revision,
            'inventory_observed_at' => $this->format($decision->inventoryObservedAt),
            'capacity_observed_at' => null === $decision->capacityObservedAt ? null : $this->format($decision->capacityObservedAt),
            'write_state_observed_at' => null === $decision->writeStateObservedAt ? null : $this->format($decision->writeStateObservedAt),
        ]);

        foreach ($decision->gates as $gate) {
            $connection->insert('scheduler_decision_gates', [
                'decision_id' => $decision->id->binary(),
                'gate_ordinal' => $gate->position,
                'code' => $gate->result->code->value,
                'passed' => $gate->result->passed ? 1 : 0,
                'scope' => $gate->result->scope->value,
                'subject_id' => $gate->result->subjectId->binary(),
                'observed_at' => null === $gate->result->observedAt ? null : $this->format($gate->result->observedAt),
                'detail_code' => $gate->result->detailCode->value,
            ]);
        }
    }

    private function assertFence(Connection $connection, CollectorLease $lease, bool $lock): void
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $schedule = $connection->fetchAssociative(
            'SELECT * FROM collector_schedule WHERE schedule_name = :name'.$suffix,
            ['name' => self::SCHEDULE_NAME],
        );
        $cycle = $connection->fetchAssociative(
            'SELECT * FROM collector_cycles WHERE cycle_token = :token'.$suffix,
            ['token' => $lease->token->binary()],
        );
        $now = $this->databaseNow($connection);
        if (false === $schedule || false === $cycle
            || !is_string($schedule['lease_owner'] ?? null)
            || !hash_equals($lease->ownerId->bytes, $schedule['lease_owner'])
            || !is_string($schedule['lease_token'] ?? null)
            || !hash_equals($lease->token->binary(), $schedule['lease_token'])
            || $this->integer($schedule, 'lease_fencing_token') !== $lease->fencingToken
            || !is_string($schedule['lease_expires_at'] ?? null)
            || $this->parseDate($schedule['lease_expires_at']) <= $now
            || ($cycle['status'] ?? null) !== 'running'
            || !is_string($cycle['worker_instance_id'] ?? null)
            || !hash_equals($lease->ownerId->bytes, $cycle['worker_instance_id'])
            || ($cycle['worker_kind'] ?? null) !== 'collector'
            || $this->integer($cycle, 'fencing_token') !== $lease->fencingToken) {
            throw new CollectorLeaseOwnershipLost('The shadow evaluation write lost its collector lease.');
        }
    }

    private function databaseNow(Connection $connection): DateTimeImmutable
    {
        $value = $connection->fetchOne('SELECT UTC_TIMESTAMP(6)');
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB did not return its UTC clock.');
        }

        return $this->parseDate($value);
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date) {
            throw new RuntimeException('MariaDB returned an invalid UTC timestamp.');
        }

        return $date;
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException('MariaDB returned an invalid integer.');
    }
}
