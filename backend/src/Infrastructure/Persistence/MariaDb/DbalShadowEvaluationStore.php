<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Backup\Queue\ExpectedBackupSize;
use App\Application\Scheduler\Shadow\AutomaticShadowPromotion;
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

    private ExpectedBackupSize $expectedSizes;
    private DbalBackupRequestGuestGuard $guestGuard;

    public function __construct(private Connection $connection, ?ExpectedBackupSize $expectedSizes = null)
    {
        $this->expectedSizes = $expectedSizes ?? new ExpectedBackupSize();
        $this->guestGuard = new DbalBackupRequestGuestGuard();
    }

    public function committedResult(
        CollectorLease $lease,
        \App\Application\Scheduler\Shadow\ShadowEvaluationRunId $runId,
        int $evaluatorVersion,
    ): ?ShadowEvaluationPersistenceResult {
        return $this->connection->transactional(function (Connection $connection) use ($lease, $runId, $evaluatorVersion): ?ShadowEvaluationPersistenceResult {
            $this->assertFence($connection, $lease, true);
            $existing = $this->findExistingEvaluation($connection, $lease->token->binary(), $runId->binary());
            if (false === $existing) {
                $this->assertFence($connection, $lease, false);

                return null;
            }

            if ($this->integer($existing, 'collector_fencing_token') !== $lease->fencingToken
                || $this->integer($existing, 'evaluator_version') !== $evaluatorVersion) {
                throw new ShadowEvaluationConflict(
                    ShadowEvaluationConflictCode::PayloadMismatch,
                    'The committed shadow evaluation belongs to another fence or evaluator version.',
                );
            }
            $this->assertFence($connection, $lease, false);

            return ShadowEvaluationPersistenceResult::AlreadyPersisted;
        });
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
            $existing = $this->findExistingEvaluation(
                $connection,
                $batch->cycleToken->binary(),
                $batch->runId->binary(),
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
                foreach ($batch->promotions as $promotion) {
                    $this->promote($connection, $promotion);
                }
                $this->assertFence($connection, $lease, false);

                return $result;
            }

            $this->lockPromotionGuestsAndAssertNoActiveRequest($connection, $batch);

            $this->assertConfigurationRevisions($connection, $batch);

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
            foreach ($batch->promotions as $promotion) {
                $this->promote($connection, $promotion);
            }

            $this->assertFence($connection, $lease, false);

            return ShadowEvaluationPersistenceResult::Persisted;
        });
    }

    /** @return array<string, mixed>|false */
    private function findExistingEvaluation(Connection $connection, string $cycleToken, string $runId): array|false
    {
        $byCycle = $connection->fetchAssociative(
            'SELECT * FROM scheduler_evaluation_runs WHERE cycle_token = :cycle_token FOR UPDATE',
            ['cycle_token' => $cycleToken],
        );
        $byRun = $connection->fetchAssociative(
            'SELECT * FROM scheduler_evaluation_runs WHERE id = :id FOR UPDATE',
            ['id' => $runId],
        );
        if (false !== $byCycle && (!is_string($byCycle['id'] ?? null) || !hash_equals($runId, $byCycle['id']))) {
            throw new ShadowEvaluationConflict(
                ShadowEvaluationConflictCode::CycleAlreadyEvaluated,
                'The collector cycle already has another shadow evaluation.',
            );
        }
        if (false !== $byRun && (!is_string($byRun['cycle_token'] ?? null) || !hash_equals($cycleToken, $byRun['cycle_token']))) {
            throw new ShadowEvaluationConflict(
                ShadowEvaluationConflictCode::RunIdReused,
                'The shadow evaluation run ID belongs to another collector cycle.',
            );
        }

        return false !== $byCycle ? $byCycle : $byRun;
    }

    private function lockPromotionGuestsAndAssertNoActiveRequest(
        Connection $connection,
        ShadowEvaluationBatch $batch,
    ): void {
        if ([] === $batch->promotions) {
            return;
        }

        $decisionGuests = [];
        foreach ($batch->decisions as $decision) {
            $decisionGuests[$decision->id->toHex()] = $decision->guestId->binary();
        }
        $guestIds = [];
        foreach ($batch->promotions as $promotion) {
            $guestIds[] = $decisionGuests[bin2hex($promotion->decisionId)]
                ?? throw new RuntimeException('An automatic promotion lost its guest guard.');
        }
        $this->guestGuard->lock($connection, $guestIds);
        foreach ($guestIds as $guestId) {
            if (null !== $this->guestGuard->activeRequestId($connection, $guestId)) {
                throw new ShadowEvaluationConflict(
                    ShadowEvaluationConflictCode::ActiveRequestChanged,
                    'An active request appeared after the automatic shadow source was read.',
                );
            }
        }
    }

    private function promote(Connection $connection, AutomaticShadowPromotion $promotion): void
    {
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT decision.*, cycle.started_at AS cycle_started_at,
       guest.provisioned_size_bytes, state.last_success_size_bytes
FROM scheduler_decisions decision
JOIN scheduler_evaluation_runs evaluation ON evaluation.id = decision.evaluation_run_id
JOIN collector_cycles cycle
  ON cycle.cycle_token = evaluation.cycle_token
 AND cycle.fencing_token = evaluation.collector_fencing_token
JOIN guests guest
  ON guest.connection_id = decision.connection_id
 AND guest.cluster_id = decision.cluster_id
 AND guest.id = decision.guest_id
LEFT JOIN guest_backup_state state
  ON state.guest_id = decision.guest_id
 AND state.policy_id = decision.policy_id
 AND state.target_id = decision.target_id
WHERE decision.id = :id
FOR UPDATE
SQL, ['id' => $promotion->decisionId]);
        if (false === $row
            || 'eligible' !== ($row['outcome'] ?? null)
            || !is_string($row['policy_snapshot_hash'] ?? null)
            || !hash_equals($promotion->resolvedPolicyHash(), $row['policy_snapshot_hash'])
            || !is_string($row['cycle_started_at'] ?? null)) {
            throw new RuntimeException('Only a matching eligible shadow winner can be promoted.');
        }

        $scheduledAt = $row['cycle_started_at'];
        $existing = $connection->fetchAssociative(<<<'SQL'
SELECT * FROM backup_requests
WHERE shadow_decision_id = :decision
   OR (policy_id = :policy AND guest_id = :guest AND scheduled_at = :scheduled)
FOR UPDATE
SQL, [
            'decision' => $promotion->decisionId,
            'policy' => $row['policy_id'],
            'guest' => $row['guest_id'],
            'scheduled' => $scheduledAt,
        ]);
        if (false !== $existing) {
            $this->assertExistingPromotion($existing, $row, $promotion, $scheduledAt);
            $event = $connection->fetchAssociative(
                "SELECT * FROM backup_request_events WHERE request_id = :request AND sequence_no = 1",
                ['request' => $promotion->requestId],
            );
            $expectedEventId = substr(hash('sha256', 'queue-event'."\0".$promotion->requestId.'1', true), 0, 16);
            if (false === $event
                || !is_string($event['id'] ?? null) || !hash_equals($expectedEventId, $event['id'])
                || !is_string($event['request_id'] ?? null) || !hash_equals($promotion->requestId, $event['request_id'])
                || 'promoted' !== ($event['event_type'] ?? null)
                || 'pending' !== ($event['state'] ?? null)
                || ($event['occurred_at'] ?? null) !== $scheduledAt) {
                throw new RuntimeException('The existing automatic request lost its promotion event.');
            }

            return;
        }

        $expected = $this->expectedSizes->calculate(
            $this->optionalDecimal($row['last_success_size_bytes'] ?? null),
            $this->optionalDecimal($row['provisioned_size_bytes'] ?? null),
        );
        if (null === $expected) {
            throw new RuntimeException('Expected backup size evidence is missing.');
        }

        $connection->insert('backup_requests', [
            'id' => $promotion->requestId,
            'root_request_id' => $promotion->requestId,
            'attempt' => 1,
            'origin' => 'automatic',
            'state' => 'pending',
            'reason' => $this->requiredText($row['reason'] ?? null),
            'priority' => $this->integer($row, 'priority'),
            'scheduled_at' => $scheduledAt,
            'available_at' => $scheduledAt,
            'connection_id' => $this->requiredBinary($row['connection_id'] ?? null),
            'cluster_id' => $this->requiredBinary($row['cluster_id'] ?? null),
            'guest_id' => $this->requiredBinary($row['guest_id'] ?? null),
            'node_id' => $this->requiredBinary($row['node_id'] ?? null),
            'placement_revision' => $this->integer($row, 'placement_revision'),
            'placement_observed_at' => $this->requiredText($row['placement_observed_at'] ?? null),
            'policy_id' => $this->requiredBinary($row['policy_id'] ?? null),
            'policy_revision' => $this->integer($row, 'policy_revision'),
            'target_id' => $this->requiredBinary($row['target_id'] ?? null),
            'target_revision' => $this->integer($row, 'target_revision'),
            'shadow_decision_id' => $promotion->decisionId,
            'resolved_policy_json' => $promotion->resolvedPolicyJson,
            'resolved_policy_hash' => $promotion->resolvedPolicyHash(),
            'expected_size_bytes' => $expected->value,
            'retry_disposition' => 'not_applicable',
            'submission_provenance' => 'not_submitted',
            'revision' => 1,
            'claim_fence' => 0,
            'created_at' => $scheduledAt,
            'updated_at' => $scheduledAt,
        ]);
        $connection->insert('backup_request_events', [
            'id' => substr(hash('sha256', 'queue-event'."\0".$promotion->requestId.'1', true), 0, 16),
            'request_id' => $promotion->requestId,
            'sequence_no' => 1,
            'event_type' => 'promoted',
            'state' => 'pending',
            'claim_fence' => null,
            'occurred_at' => $scheduledAt,
            'detail_code' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $decision
     */
    private function assertExistingPromotion(
        array $existing,
        array $decision,
        AutomaticShadowPromotion $promotion,
        string $scheduledAt,
    ): void {
        $binaryFields = [
            'id' => $promotion->requestId,
            'root_request_id' => $promotion->requestId,
            'shadow_decision_id' => $promotion->decisionId,
            'connection_id' => $decision['connection_id'] ?? null,
            'cluster_id' => $decision['cluster_id'] ?? null,
            'guest_id' => $decision['guest_id'] ?? null,
            'node_id' => $decision['node_id'] ?? null,
            'policy_id' => $decision['policy_id'] ?? null,
            'target_id' => $decision['target_id'] ?? null,
            'resolved_policy_hash' => $promotion->resolvedPolicyHash(),
        ];
        foreach ($binaryFields as $field => $expected) {
            if (!is_string($existing[$field] ?? null) || !is_string($expected)
                || !hash_equals($expected, $existing[$field])) {
                throw new RuntimeException('An existing automatic request conflicts with its shadow winner.');
            }
        }
        foreach (['placement_revision', 'policy_revision', 'target_revision', 'priority'] as $field) {
            if ($this->integer($existing, $field) !== $this->integer($decision, $field)) {
                throw new RuntimeException('An existing automatic request conflicts with its shadow winner.');
            }
        }
        if ('automatic' !== ($existing['origin'] ?? null)
            || 1 !== $this->mixedInteger($existing['attempt'] ?? null)
            || ($existing['reason'] ?? null) !== ($decision['reason'] ?? null)
            || ($existing['scheduled_at'] ?? null) !== $scheduledAt
            || ($existing['created_at'] ?? null) !== $scheduledAt
            || ($existing['resolved_policy_json'] ?? null) !== $promotion->resolvedPolicyJson) {
            throw new RuntimeException('An existing automatic request conflicts with its shadow winner.');
        }
    }

    private function requiredBinary(mixed $value): string
    {
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid automatic request identifier.');
        }
        return $value;
    }

    private function requiredText(mixed $value): string
    {
        if (!is_string($value) || '' === $value) {
            throw new RuntimeException('MariaDB returned invalid automatic request evidence.');
        }
        return $value;
    }

    private function optionalDecimal(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if ((is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value))) {
            return (string) $value;
        }
        throw new RuntimeException('MariaDB returned invalid expected-size evidence.');
    }

    private function mixedInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new RuntimeException('MariaDB returned an invalid integer.');
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

    private function assertConfigurationRevisions(Connection $connection, ShadowEvaluationBatch $batch): void
    {
        foreach ($batch->decisions as $decision) {
            if (null === $decision->policy || null === $decision->target) {
                continue;
            }
            $policy = $connection->fetchAssociative(
                'SELECT revision, status FROM backup_policies WHERE id = :id FOR UPDATE',
                ['id' => $decision->policy->policyId->binary()],
            );
            $target = $connection->fetchAssociative(
                'SELECT revision, status FROM backup_targets WHERE id = :id FOR UPDATE',
                ['id' => $decision->target->targetId->binary()],
            );
            if (false === $policy || false === $target
                || $this->integer($policy, 'revision') !== $decision->policy->revision
                || $this->integer($target, 'revision') !== $decision->target->revision
                || 'enabled' !== ($policy['status'] ?? null)
                || 'enabled' !== ($target['status'] ?? null)) {
                throw new ShadowEvaluationConflict(
                    ShadowEvaluationConflictCode::PayloadMismatch,
                    'The policy or target changed during shadow evaluation.',
                );
            }
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
