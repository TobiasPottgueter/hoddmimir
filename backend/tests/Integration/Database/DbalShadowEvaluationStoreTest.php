<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Backup\Queue\ClaimNextBackupCommand;
use App\Application\Backup\Queue\ExpectedBackupSize;
use App\Application\Backup\Queue\QueueClaimTokenSource;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Scheduler\Shadow\OrderedShadowGate;
use App\Application\Scheduler\Shadow\AutomaticShadowPromotion;
use App\Application\Scheduler\Shadow\ShadowDecision;
use App\Application\Scheduler\Shadow\ShadowDecisionId;
use App\Application\Scheduler\Shadow\ShadowEvaluationBatch;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflict;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflictCode;
use App\Application\Scheduler\Shadow\ShadowEvaluationPersistenceResult;
use App\Application\Scheduler\Shadow\ShadowEvaluationRunId;
use App\Application\Scheduler\Shadow\ShadowPlacementEvidence;
use App\Application\Scheduler\Shadow\ShadowPolicyEvidence;
use App\Application\Scheduler\Shadow\ShadowTargetEvidence;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateResult;
use App\Domain\Scheduler\GateScope;
use App\Domain\Scheduler\GateSubjectId;
use App\Domain\Scheduler\Priority;
use App\Domain\Scheduler\ReasonPriority;
use App\Infrastructure\Persistence\MariaDb\DbalShadowEvaluationStore;
use App\Infrastructure\Persistence\MariaDb\DbalBackupQueueStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

final class DbalShadowEvaluationStoreTest extends DatabaseTestCase
{
    private CollectorLease $lease;
    private InventoryIdentifier $connectionId;
    private InventoryIdentifier $clusterId;
    private InventoryIdentifier $nodeId;
    private InventoryIdentifier $guestId;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = $this->databaseNow();
        $this->seedLeaseAndGuest();
    }

    public function testPersistsCompleteBatchAtomicallyAndReplaysIdempotently(): void
    {
        $store = new DbalShadowEvaluationStore($this->connection());
        $batch = $this->batch('run-a');

        self::assertSame(ShadowEvaluationPersistenceResult::Persisted, $store->persist($this->lease, $batch));
        self::assertSame(ShadowEvaluationPersistenceResult::AlreadyPersisted, $store->persist($this->lease, $batch));
        self::assertSame('1', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM scheduler_evaluation_runs')));
        self::assertSame('1', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM scheduler_decisions')));
        self::assertSame('1', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM scheduler_decision_gates')));

        $row = $this->connection()->fetchAssociative('SELECT * FROM scheduler_decisions');
        self::assertIsArray($row);
        self::assertSame('eligible', $row['outcome']);
        self::assertSame('never_backed_up', $row['reason']);
        self::assertSame('300', $this->numericString($row['priority'] ?? null));
        self::assertSame('1', $this->numericString($row['placement_revision'] ?? null));
    }

    public function testChangedReplayFailsClosed(): void
    {
        $store = new DbalShadowEvaluationStore($this->connection());
        $store->persist($this->lease, $this->batch('run-a'));

        try {
            $store->persist($this->lease, $this->batch('run-a', evaluatorVersion: 2));
            self::fail('A changed replay must not be accepted.');
        } catch (ShadowEvaluationConflict $conflict) {
            self::assertSame(ShadowEvaluationConflictCode::PayloadMismatch, $conflict->failureCode);
        }
    }

    public function testEligibleWinnerIsPromotedAtomicallyFromCollectorStartAndReplayIsExact(): void
    {
        $store = new DbalShadowEvaluationStore($this->connection());
        $batch = $this->batch('promoted-run', withPromotion: true);

        self::assertSame(ShadowEvaluationPersistenceResult::Persisted, $store->persist($this->lease, $batch));
        self::assertSame(ShadowEvaluationPersistenceResult::AlreadyPersisted, $store->persist($this->lease, $batch));
        $request = $this->connection()->fetchAssociative('SELECT * FROM backup_requests');
        self::assertIsArray($request);
        self::assertSame('automatic', $request['origin']);
        self::assertSame('pending', $request['state']);
        self::assertSame('never_backed_up', $request['reason']);
        self::assertSame('300', $this->numericString($request['priority'] ?? null));
        self::assertSame(self::format($this->now), $request['scheduled_at']);
        self::assertSame(self::format($this->now), $request['available_at']);
        self::assertSame(self::format($this->now), $request['created_at']);
        self::assertSame('{"mode":"snapshot"}', $request['resolved_policy_json']);
        self::assertSame('1', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_requests')));
        self::assertSame('1', $this->numericString($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_request_events WHERE event_type='promoted'")));
    }

    public function testExecutionDisabledLeavesAutomaticallyPromotedRequestPendingAndUnclaimed(): void
    {
        $requestId = self::bytes('request-disabled-execution-run');
        (new DbalShadowEvaluationStore($this->connection()))->persist(
            $this->lease,
            $this->batch('disabled-execution-run', withPromotion: true),
        );
        $tokens = new class implements QueueClaimTokenSource {
            public function next(): string { return str_repeat('t', 16); }
        };
        $queue = new DbalBackupQueueStore(
            $this->connection(),
            $tokens,
            new ExpectedBackupSize(),
            new EvidenceFreshnessPolicy(),
        );

        self::assertNull($queue->claim(new ClaimNextBackupCommand(
            self::bytes('disabled-worker'),
            $this->now,
            allowNewClaims: false,
        )));
        self::assertSame('pending', $this->connection()->fetchOne('SELECT state FROM backup_requests'));
        self::assertSame('0', $this->numericString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM backup_runs WHERE request_id = :request_id',
            ['request_id' => $requestId],
            ['request_id' => \Doctrine\DBAL\ParameterType::BINARY],
        )));
    }

    public function testReplayAcceptsLegitimateQueueProgressAfterPromotion(): void
    {
        $store = new DbalShadowEvaluationStore($this->connection());
        $batch = $this->batch('progressed-replay', withPromotion: true);
        $store->persist($this->lease, $batch);
        $availableAt = self::format($this->now->modify('+1 minute'));
        $this->connection()->update('backup_requests', [
            'state' => 'retry_wait',
            'available_at' => $availableAt,
            'updated_at' => $availableAt,
        ], []);

        self::assertSame(ShadowEvaluationPersistenceResult::AlreadyPersisted, $store->persist($this->lease, $batch));
        self::assertSame('retry_wait', $this->connection()->fetchOne('SELECT state FROM backup_requests'));
        self::assertSame($availableAt, $this->connection()->fetchOne('SELECT available_at FROM backup_requests'));
    }

    #[DataProvider('corruptedPromotionReplayProvider')]
    public function testChangedPromotionReplayFailsClosed(string $case): void
    {
        $store = new DbalShadowEvaluationStore($this->connection());
        $batch = $this->batch('corrupt-'.$case, withPromotion: true);
        $store->persist($this->lease, $batch);

        match ($case) {
            'created' => $this->connection()->update('backup_requests', ['created_at' => self::format($this->now->modify('-1 second'))], []),
            'event-id' => $this->connection()->update('backup_request_events', ['id' => self::bytes('corrupt-event')], []),
            default => throw new \LogicException('Unknown corrupted promotion replay case.'),
        };

        try {
            $store->persist($this->lease, $batch);
            self::fail('A changed automatic promotion replay must fail closed.');
        } catch (\RuntimeException $failure) {
            self::assertSame(
                'event-id' === $case
                    ? 'The existing automatic request lost its promotion event.'
                    : 'An existing automatic request conflicts with its shadow winner.',
                $failure->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function corruptedPromotionReplayProvider(): iterable
    {
        foreach (['created', 'event-id'] as $case) {
            yield $case => [$case];
        }
    }

    public function testPromotionPreparationFailureRollsBackShadowDecisionAndRequestTogether(): void
    {
        $this->connection()->update('guests', ['provisioned_size_bytes' => null], ['id' => $this->guestId->binary()]);
        try {
            (new DbalShadowEvaluationStore($this->connection()))->persist(
                $this->lease,
                $this->batch('rollback-run', withPromotion: true),
            );
            self::fail('Missing promotion evidence must abort the whole shadow commit.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Expected backup size evidence is missing.', $failure->getMessage());
            self::assertSame('0', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM scheduler_evaluation_runs')));
            self::assertSame('0', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM scheduler_decisions')));
            self::assertSame('0', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_requests')));
            self::assertSame('0', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_request_events')));
        }
    }

    public function testDifferentRunForSameCycleFailsClosed(): void
    {
        $store = new DbalShadowEvaluationStore($this->connection());
        $store->persist($this->lease, $this->batch('run-a'));

        try {
            $store->persist($this->lease, $this->batch('run-b'));
            self::fail('A collector cycle must not gain a second evaluation.');
        } catch (ShadowEvaluationConflict $conflict) {
            self::assertSame(ShadowEvaluationConflictCode::CycleAlreadyEvaluated, $conflict->failureCode);
        }
    }

    public function testLostFenceRejectsWriteWithoutResidue(): void
    {
        $this->connection()->update('collector_schedule', ['lease_owner' => self::bytes('foreign-worker')], ['schedule_name' => 'inventory']);

        $this->expectException(CollectorLeaseOwnershipLost::class);
        try {
            (new DbalShadowEvaluationStore($this->connection()))->persist($this->lease, $this->batch('run-a'));
        } finally {
            self::assertSame('0', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM scheduler_evaluation_runs')));
        }
    }

    public function testDecisionForeignKeyFailureRollsBackRun(): void
    {
        $store = new DbalShadowEvaluationStore($this->connection());
        $invalid = $this->batch('run-a', guestId: self::id('missing-guest'));

        try {
            $store->persist($this->lease, $invalid);
            self::fail('An unknown guest must fail the shadow transaction.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame('0', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM scheduler_evaluation_runs')));
            self::assertSame('0', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM scheduler_decisions')));
        }
    }

    public function testEmptyEvaluationIsPersistedWithoutSyntheticDecisions(): void
    {
        $batch = new ShadowEvaluationBatch(
            new ShadowEvaluationRunId(self::bytes('empty-run')),
            $this->lease->token,
            1,
            $this->now->modify('-2 seconds'),
            $this->now->modify('-1 second'),
            [],
        );

        self::assertSame(
            ShadowEvaluationPersistenceResult::Persisted,
            (new DbalShadowEvaluationStore($this->connection()))->persist($this->lease, $batch),
        );
        self::assertSame('0', $this->numericString($this->connection()->fetchOne('SELECT decision_count FROM scheduler_evaluation_runs')));
    }

    public function testSchemaRejectsNonCanonicalDecisionAndGateEvidence(): void
    {
        (new DbalShadowEvaluationStore($this->connection()))->persist($this->lease, $this->batch('run-a'));
        $decisionId = self::bytes('decision-run-a');

        $this->assertConstraintViolation(fn () => $this->connection()->update(
            'scheduler_decisions',
            ['priority' => 100],
            ['id' => $decisionId],
        ));
        $this->assertConstraintViolation(fn () => $this->connection()->update(
            'scheduler_decisions',
            ['priority' => null],
            ['id' => $decisionId],
        ));
        $this->assertConstraintViolation(fn () => $this->connection()->update(
            'scheduler_decisions',
            ['reason' => null],
            ['id' => $decisionId],
        ));
        $this->assertConstraintViolation(fn () => $this->connection()->update(
            'scheduler_decisions',
            ['policy_revision' => null],
            ['id' => $decisionId],
        ));
        $this->assertConstraintViolation(fn () => $this->connection()->update(
            'scheduler_decisions',
            ['placement_revision' => null],
            ['id' => $decisionId],
        ));
        $this->assertConstraintViolation(fn () => $this->connection()->update(
            'scheduler_decisions',
            ['node_id' => self::bytes('missing-node')],
            ['id' => $decisionId],
        ));
        $this->assertConstraintViolation(fn () => $this->connection()->update(
            'scheduler_decisions',
            ['outcome' => 'claimable'],
            ['id' => $decisionId],
        ));
        $this->assertConstraintViolation(fn () => $this->connection()->update(
            'scheduler_decision_gates',
            ['passed' => 0],
            ['decision_id' => $decisionId, 'gate_ordinal' => 1],
        ));
        $this->assertConstraintViolation(fn () => $this->connection()->update(
            'scheduler_decision_gates',
            ['code' => 'start_backup'],
            ['decision_id' => $decisionId, 'gate_ordinal' => 1],
        ));
    }

    public function testConcurrentIdenticalWritersConvergeOnOneRun(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the shadow concurrency proof.');
        }

        $batch = $this->batch('concurrent-run', withPromotion: true);
        $parameters = $this->connection()->getParams();
        $this->connection()->commit();
        /** @var list<array{int, resource}> $children */
        $children = [
            $this->startChildWriter($parameters, $batch),
            $this->startChildWriter($parameters, $batch),
        ];

        try {
            foreach ($children as [, $socket]) {
                self::assertSame(1, fwrite($socket, '1'));
            }
            $results = [];
            foreach ($children as [$pid, $socket]) {
                $results[] = $this->finishChildWriter($pid, $socket);
            }
            sort($results, SORT_STRING);
            self::assertSame(['already_persisted', 'persisted'], $results);
            self::assertSame('1', $this->numericString(
                $this->connection()->fetchOne('SELECT COUNT(*) FROM scheduler_evaluation_runs'),
            ));
            self::assertSame('1', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_requests')));
            self::assertSame('1', $this->numericString($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_request_events')));
        } finally {
            $this->cleanupCommittedFixture();
        }
    }

    private function batch(
        string $runLabel,
        int $evaluatorVersion = 1,
        ?InventoryIdentifier $guestId = null,
        bool $withPromotion = false,
    ): ShadowEvaluationBatch {
        $placementObservedAt = $this->now->modify('-4 seconds');
        $policyJson = '{"mode":"snapshot"}';
        $decision = new ShadowDecision(
            new ShadowDecisionId(self::bytes('decision-'.$runLabel)),
            $this->connectionId,
            $this->clusterId,
            $guestId ?? $this->guestId,
            new ShadowPlacementEvidence($this->nodeId, 1, $placementObservedAt),
            DecisionOutcome::Eligible,
            new ReasonPriority(BackupReason::NeverBackedUp, Priority::NeverBackedUp),
            new ShadowPolicyEvidence(self::id('policy'), 1, hash('sha256', $withPromotion ? $policyJson : 'policy', true)),
            new ShadowTargetEvidence(self::id('target'), 1),
            $this->now->modify('-4 seconds'),
            $this->now->modify('-3 seconds'),
            $this->now->modify('-3 seconds'),
            [new OrderedShadowGate(1, new GateResult(
                GateCode::PlacementPresent,
                true,
                GateScope::Placement,
                new GateSubjectId(($guestId ?? $this->guestId)->binary()),
                $placementObservedAt,
                GateDetailCode::Passed,
            ))],
        );

        $promotion = new AutomaticShadowPromotion(
            self::bytes('request-'.$runLabel),
            $decision->id->binary(),
            $policyJson,
            hash('sha256', $policyJson, true),
        );

        return new ShadowEvaluationBatch(
            new ShadowEvaluationRunId(self::bytes($runLabel)),
            $this->lease->token,
            $evaluatorVersion,
            $this->now->modify('-2 seconds'),
            $this->now->modify('-1 second'),
            [$decision],
            $withPromotion ? [$promotion] : [],
        );
    }

    private function seedLeaseAndGuest(): void
    {
        $worker = new CollectorWorkerId(self::bytes('worker'));
        $token = new CollectorCycleToken(self::bytes('cycle'));
        $expires = $this->now->modify('+1 hour');
        $formattedNow = self::format($this->now);
        $this->connection()->insert('worker_heartbeats', [
            'worker_instance_id' => $worker->bytes,
            'worker_kind' => 'collector',
            'status' => 'ready',
            'started_at' => $formattedNow,
            'heartbeat_at' => $formattedNow,
            'expires_at' => self::format($expires),
            'current_activity' => null,
            'current_cycle_token' => null,
            'next_action_at' => null,
            'build_version' => 'test',
        ]);
        $this->connection()->insert('collector_schedule', [
            'schedule_name' => 'inventory',
            'grid_started_at' => $formattedNow,
            'interval_seconds' => 120,
            'next_scan_at' => $formattedNow,
            'lease_owner' => $worker->bytes,
            'lease_token' => $token->binary(),
            'lease_fencing_token' => 1,
            'lease_acquired_at' => $formattedNow,
            'lease_expires_at' => self::format($expires),
            'last_cycle_started_at' => $formattedNow,
            'updated_at' => $formattedNow,
        ]);
        $this->connection()->insert('collector_cycles', [
            'cycle_token' => $token->binary(),
            'schedule_name' => 'inventory',
            'worker_instance_id' => $worker->bytes,
            'worker_kind' => 'collector',
            'fencing_token' => 1,
            'scheduled_for' => $formattedNow,
            'started_at' => $formattedNow,
            'heartbeat_at' => $formattedNow,
            'status' => 'running',
        ]);
        $this->lease = new CollectorLease($worker, $token, 1, $expires);

        $this->connectionId = self::id('connection');
        $this->clusterId = self::id('cluster');
        $this->nodeId = self::id('node');
        $this->guestId = self::id('guest');
        $runId = self::id('inventory-run');
        $this->connection()->insert('proxmox_connections', [
            'id' => $this->connectionId->binary(),
            'display_name' => 'Shadow test',
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => $formattedNow,
            'updated_at' => $formattedNow,
        ]);
        $this->connection()->insert('inventory_sync_runs', [
            'id' => $runId->binary(),
            'cycle_token' => $token->binary(),
            'collector_fencing_token' => 1,
            'connection_id' => $this->connectionId->binary(),
            'expected_connection_revision' => 1,
            'status' => 'succeeded',
            'authoritative' => 1,
            'started_at' => $formattedNow,
            'heartbeat_at' => $formattedNow,
            'finished_at' => $formattedNow,
            'applied_at' => $formattedNow,
        ]);
        $this->connection()->insert('pve_clusters', [
            'id' => $this->clusterId->binary(),
            'connection_id' => $this->connectionId->binary(),
            'external_name' => 'cluster',
            'topology' => 'clustered',
            'inventory_state' => 'active',
            'first_seen_run_id' => $runId->binary(),
            'last_seen_run_id' => $runId->binary(),
            'first_seen_at' => $formattedNow,
            'last_seen_at' => $formattedNow,
        ]);
        $this->connection()->insert('pve_nodes', [
            'id' => $this->nodeId->binary(),
            'connection_id' => $this->connectionId->binary(),
            'cluster_id' => $this->clusterId->binary(),
            'node_name' => 'node-a',
            'api_status' => 'online',
            'inventory_state' => 'active',
            'first_seen_run_id' => $runId->binary(),
            'last_seen_run_id' => $runId->binary(),
            'first_seen_at' => $formattedNow,
            'last_seen_at' => $formattedNow,
        ]);
        $storageId = self::id('storage');
        $this->connection()->insert('pve_storages', [
            'id' => $storageId->binary(),
            'connection_id' => $this->connectionId->binary(),
            'cluster_id' => $this->clusterId->binary(),
            'storage_name' => 'backup-a',
            'storage_type' => 'dir',
            'supports_backup' => 1,
            'disabled' => 0,
            'content_json' => '["backup"]',
            'shared' => 1,
            'inventory_state' => 'active',
            'first_seen_run_id' => $runId->binary(),
            'last_seen_run_id' => $runId->binary(),
            'first_seen_at' => $formattedNow,
            'last_seen_at' => $formattedNow,
        ]);
        $this->connection()->insert('backup_targets', [
            'id' => self::bytes('target'),
            'connection_id' => $this->connectionId->binary(),
            'cluster_id' => $this->clusterId->binary(),
            'storage_id' => $storageId->binary(),
            'display_name' => 'Shadow target',
            'status' => 'enabled',
            'revision' => 1,
            'minimum_free_bytes' => '0',
            'fixed_parallel_limit' => 1,
            'created_at' => $formattedNow,
            'updated_at' => $formattedNow,
            'disabled_at' => null,
        ]);
        $this->connection()->insert('backup_policies', [
            'id' => self::bytes('policy'),
            'connection_id' => $this->connectionId->binary(),
            'cluster_id' => $this->clusterId->binary(),
            'target_id' => self::bytes('target'),
            'display_name' => 'Shadow policy',
            'status' => 'enabled',
            'revision' => 1,
            'policy_priority' => 100,
            'backup_mode' => 'snapshot',
            'compression' => 'zstd',
            'maximum_age_seconds' => 3600,
            'schedule' => 'collector_cycle',
            'keep_last' => 1,
            'retention_execution_enabled' => 0,
            'created_at' => $formattedNow,
            'updated_at' => $formattedNow,
            'disabled_at' => null,
        ]);
        $this->connection()->insert('guests', [
            'id' => $this->guestId->binary(),
            'connection_id' => $this->connectionId->binary(),
            'cluster_id' => $this->clusterId->binary(),
            'guest_type' => 'qemu',
            'vmid' => 100,
            'name' => 'guest-a',
            'is_template' => 0,
            'provisioned_size_bytes' => '1000',
            'inventory_state' => 'active',
            'first_seen_run_id' => $runId->binary(),
            'last_seen_run_id' => $runId->binary(),
            'first_seen_at' => $formattedNow,
            'last_seen_at' => $formattedNow,
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{int, resource}
     */
    private function startChildWriter(array $parameters, ShadowEvaluationBatch $batch): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $sockets) {
            throw new \RuntimeException('Could not create shadow concurrency sockets.');
        }
        [$parent, $child] = $sockets;
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('Could not fork a shadow writer.');
        }
        if (0 === $pid) {
            fclose($parent);
            if ('1' !== fread($child, 1)) {
                exit(2);
            }
            // @phpstan-ignore argument.type (parameters originate from a live DBAL connection)
            $connection = DriverManager::getConnection($parameters);
            try {
                $result = (new DbalShadowEvaluationStore($connection))->persist($this->lease, $batch);
                fwrite($child, $result->value);
            } catch (Throwable $exception) {
                fwrite($child, $exception::class.':'.$exception->getMessage());
            } finally {
                $connection->close();
                fclose($child);
            }
            pcntl_exec('/bin/true');
            posix_kill(posix_getpid(), SIGKILL);
            exit(3);
        }

        fclose($child);
        stream_set_timeout($parent, 15);

        return [$pid, $parent];
    }

    /** @param resource $socket */
    private function finishChildWriter(int $pid, $socket): string
    {
        $result = stream_get_contents($socket);
        fclose($socket);
        $processStatus = null;
        pcntl_waitpid($pid, $processStatus);
        if (!is_int($processStatus)) {
            throw new \RuntimeException('The shadow writer returned no process status.');
        }
        self::assertTrue(pcntl_wifexited($processStatus));
        self::assertSame(0, pcntl_wexitstatus($processStatus));
        self::assertIsString($result);
        self::assertContains($result, ['persisted', 'already_persisted']);

        return $result;
    }

    private function cleanupCommittedFixture(): void
    {
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([
                'backup_request_events', 'backup_requests',
                'scheduler_decision_gates', 'scheduler_decisions', 'scheduler_evaluation_runs',
                'backup_policy_guest_overrides', 'backup_policy_assignments', 'backup_policies',
                'backup_target_allowed_nodes', 'backup_targets', 'pve_storages',
                'guests', 'pve_nodes', 'pve_clusters', 'inventory_sync_runs',
                'proxmox_connections', 'collector_cycles', 'collector_schedule', 'worker_heartbeats',
            ] as $table) {
                $this->connection()->executeStatement('DELETE FROM '.$table);
            }
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function databaseNow(): DateTimeImmutable
    {
        $value = $this->connection()->fetchOne('SELECT UTC_TIMESTAMP(6)');
        self::assertIsString($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $date);

        return $date;
    }

    /** @param callable(): mixed $operation */
    private function assertConstraintViolation(callable $operation): void
    {
        try {
            $operation();
            self::fail('MariaDB accepted invalid shadow-evaluation evidence.');
        } catch (\Doctrine\DBAL\Exception) {
            self::addToAssertionCount(1);
        }
    }

    private function numericString(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return $value;
        }

        self::fail('MariaDB did not return an unsigned integer.');
    }

    private static function id(string $label): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes($label));
    }

    private static function bytes(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
