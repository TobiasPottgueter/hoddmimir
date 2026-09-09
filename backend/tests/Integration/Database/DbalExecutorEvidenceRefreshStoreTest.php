<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Backup\Execution\ExecutorEvidenceLeaseOwnershipLost;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshClaim;
use App\Application\Backup\Execution\ExecutorPermissionProjection;
use App\Application\Backup\Queue\QueueClaimTokenSource;
use App\Infrastructure\Persistence\MariaDb\DbalExecutorEvidenceRefreshStore;
use App\Infrastructure\Persistence\MariaDb\DbalPveExecutorEvidenceConfigurationSource;
use App\Infrastructure\Proxmox\PveTlsMode;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;

final class DbalExecutorEvidenceRefreshStoreTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-16 10:00:00.000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTarget();
    }

    public function testDisabledTargetIsClaimedPublishedAndImmediatelyInvalidatedByCredentialRotation(): void
    {
        $store = $this->store();
        $claim = $this->claim($store);
        $subjects = $store->subjects($claim, null, 10);
        self::assertCount(1, $subjects, 'Draft/disabled target evidence must be producible before activation.');
        self::assertNull($subjects[0]->guestId);

        $observed = $this->databaseNow();
        $store->bindSnapshotEndpoint($claim, self::id('endpoint'), $observed);
        $store->stage($claim, [new ExecutorPermissionProjection(
            $subjects[0], self::id('endpoint'), 1, 1, 1, true, true,
        )]);
        $store->publish($claim, $observed);

        self::assertSame('1', $this->scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM current_executor_permission_evidence',
        )));
        self::assertSame('1', $this->scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM executor_permission_evidence',
        )));

        $this->connection()->update('proxmox_credentials', ['revision' => 2], [
            'connection_id' => self::id('connection'),
            'purpose' => 'collector',
        ]);
        self::assertSame('0', $this->scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM current_executor_permission_evidence',
        )));
    }

    public function testFailedRefreshPreservesPublishedSetAndSuccessfulEmptyRefreshRemovesIt(): void
    {
        $store = $this->store();
        $first = $this->claim($store);
        $this->publishClaim($store, $first);
        self::assertSame('1', $this->scalarString($this->connection()->fetchOne('SELECT COUNT(*) FROM current_executor_permission_evidence')));

        $this->makeDue();
        $failed = $this->claim($store);
        $store->fail($failed, \App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode::Transport, $this->databaseNow());
        self::assertSame('1', $this->scalarString($this->connection()->fetchOne('SELECT COUNT(*) FROM current_executor_permission_evidence')));

        $this->connection()->delete('backup_target_allowed_nodes', ['target_id' => self::id('target')]);
        $this->makeDue();
        $empty = $this->claim($store);
        self::assertSame([], $store->subjects($empty, null, 10));
        $observed = $this->databaseNow();
        $store->bindSnapshotEndpoint($empty, self::id('endpoint'), $observed);
        $store->publish($empty, $observed);
        self::assertSame('0', $this->scalarString($this->connection()->fetchOne('SELECT COUNT(*) FROM current_executor_permission_evidence')));
        self::assertSame('0', $this->scalarString($this->connection()->fetchOne('SELECT COUNT(*) FROM executor_permission_evidence')));
    }

    /** @param array<string, mixed> $parameters */
    #[DataProvider('revisionRotationProvider')]
    public function testEveryConfigurationRevisionRotationHidesPublishedEvidenceAndInvalidatesTheClaim(
        string $sql,
        array $parameters,
    ): void {
        $store = $this->store();
        $this->publishClaim($store, $this->claim($store));
        self::assertSame('1', $this->scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM current_executor_permission_evidence',
        )));

        $this->makeDue();
        $active = $this->claim($store);
        $this->connection()->executeStatement($sql, $parameters);
        self::assertSame('0', $this->scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM current_executor_permission_evidence',
        )));
        self::assertSame('1', $this->scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM executor_permission_evidence',
        )));

        $this->expectException(ExecutorEvidenceLeaseOwnershipLost::class);
        $store->renew($active, $this->databaseNow());
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function revisionRotationProvider(): iterable
    {
        yield 'connection revision' => [
            'UPDATE proxmox_connections SET revision = 2 WHERE id = :id',
            ['id' => self::id('connection')],
        ];
        yield 'backup credential revision' => [
            "UPDATE proxmox_credentials SET revision = 2 WHERE connection_id = :connection AND purpose = 'backup'",
            ['connection' => self::id('connection')],
        ];
        yield 'scan credential revision' => [
            "UPDATE proxmox_credentials SET revision = 2 WHERE connection_id = :connection AND purpose = 'collector'",
            ['connection' => self::id('connection')],
        ];
    }

    public function testIncompleteProjectionPublicationRollsBackAndPreservesThePreviousSet(): void
    {
        $store = $this->store();
        $first = $this->claim($store);
        $this->publishClaim($store, $first);
        $publishedFence = $first->leaseFence;

        $this->makeDue();
        $incomplete = $this->claim($store);
        $observed = $this->databaseNow();
        $store->bindSnapshotEndpoint($incomplete, self::id('endpoint'), $observed);
        try {
            $store->publish($incomplete, $observed);
            self::fail('An incomplete executor evidence set was published.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Executor evidence projection stage is incomplete.', $failure->getMessage());
        }

        self::assertSame('1', $this->scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM current_executor_permission_evidence',
        )));
        self::assertSame((string) $publishedFence, $this->scalarString($this->connection()->fetchOne(
            'SELECT evidence_set_revision FROM executor_permission_evidence',
        )));
        self::assertSame((string) $publishedFence, $this->scalarString($this->connection()->fetchOne(
            'SELECT published_set_revision FROM executor_evidence_refresh_state WHERE connection_id = :connection',
            ['connection' => self::id('connection')],
        )));
    }

    public function testPublicationRequiresTheExactBoundObservationTimestamp(): void
    {
        $store = $this->store();
        $claim = $this->claim($store);
        $subject = $store->subjects($claim, null, 10)[0];
        $observed = $this->databaseNow();
        $store->bindSnapshotEndpoint($claim, self::id('endpoint'), $observed);
        $store->stage($claim, [new ExecutorPermissionProjection(
            $subject, self::id('endpoint'), 1, 1, 1, true, true,
        )]);

        try {
            $store->publish($claim, $observed->modify('+1 microsecond'));
            self::fail('A publication with a different observation timestamp was accepted.');
        } catch (\InvalidArgumentException $failure) { // @phpstan-ignore catch.neverThrown
            self::assertSame(
                'Executor evidence publication does not match its snapshot binding.',
                $failure->getMessage(),
            );
        }
        $store->publish($claim, $observed); // @phpstan-ignore deadCode.unreachable
        self::assertSame('1', $this->scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM current_executor_permission_evidence',
        )));
        $timestamps = $this->connection()->fetchAssociative(<<<'SQL'
SELECT evidence.observed_at, state.last_success_at
FROM current_executor_permission_evidence evidence
INNER JOIN executor_evidence_refresh_state state ON state.connection_id = evidence.connection_id
WHERE evidence.connection_id = :connection
SQL, ['connection' => self::id('connection')]);
        self::assertIsArray($timestamps);
        self::assertSame($observed->format('Y-m-d H:i:s.u'), $timestamps['observed_at']);
        self::assertSame($observed->format('Y-m-d H:i:s.u'), $timestamps['last_success_at']);
    }

    public function testRenewExtendsTheLeaseFromTheDatabaseClock(): void
    {
        $store = $this->store(90);
        $claim = $this->claim($store);
        $this->connection()->executeStatement(<<<'SQL'
UPDATE executor_evidence_refresh_state
SET lease_expires_at = UTC_TIMESTAMP(6) + INTERVAL 1 SECOND
WHERE connection_id = :connection
SQL, ['connection' => self::id('connection')]);
        $before = $this->connection()->fetchOne(
            'SELECT lease_expires_at FROM executor_evidence_refresh_state WHERE connection_id = :connection',
            ['connection' => self::id('connection')],
        );
        self::assertIsString($before);

        $store->renew($claim, $this->databaseNow());
        $after = $this->connection()->fetchOne(
            'SELECT lease_expires_at FROM executor_evidence_refresh_state WHERE connection_id = :connection',
            ['connection' => self::id('connection')],
        );
        self::assertIsString($after);
        self::assertGreaterThan($before, $after);
    }

    public function testExpiredBoundLeaseCanBeTakenOverAndOldFenceCannotContinue(): void
    {
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => self::id('endpoint-b'), 'connection_id' => self::id('connection'),
            'host' => 'pve-b.test', 'port' => 8006, 'priority' => 20, 'enabled' => 1,
            'tls_mode' => 'system_ca', 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $store = $this->store(61);
        $old = $this->claim($store, self::id('worker-a'));
        $oldSubject = $store->subjects($old, null, 10)[0];
        $oldObserved = $this->databaseNow();
        $store->bindSnapshotEndpoint($old, self::id('endpoint'), $oldObserved);
        $this->connection()->executeStatement(<<<'SQL'
UPDATE executor_evidence_refresh_state
SET lease_expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 MICROSECOND,
    next_due_at = UTC_TIMESTAMP(6) - INTERVAL 1 MICROSECOND
WHERE connection_id = :connection
SQL, ['connection' => self::id('connection')]);

        $new = $this->claim($store, self::id('worker-b'));
        self::assertGreaterThan($old->leaseFence, $new->leaseFence);
        $binding = $this->connection()->fetchAssociative(<<<'SQL'
SELECT lease_endpoint_id, lease_observed_at
FROM executor_evidence_refresh_state
WHERE connection_id = :connection
SQL, ['connection' => self::id('connection')]);
        self::assertIsArray($binding);
        self::assertNull($binding['lease_endpoint_id']);
        self::assertNull($binding['lease_observed_at']);
        $store->bindSnapshotEndpoint($new, self::id('endpoint-b'), $this->databaseNow());

        $oldProjection = new ExecutorPermissionProjection(
            $oldSubject, self::id('endpoint'), 1, 1, 1, true, true,
        );
        $now = $this->databaseNow();
        foreach ([
            static fn () => $store->renew($old, $now),
            static fn () => $store->bindSnapshotEndpoint($old, self::id('endpoint'), $oldObserved),
            static fn () => $store->subjects($old, null, 10),
            static fn () => $store->stage($old, [$oldProjection]),
            static fn () => $store->publish($old, $oldObserved),
            static fn () => $store->fail($old, \App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode::Transport, $now),
        ] as $operation) {
            try {
                $operation();
                self::fail('An expired executor-evidence claim crossed a boundary after takeover.');
            } catch (ExecutorEvidenceLeaseOwnershipLost) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testProjectionPagesCannotChangeTheBoundEndpoint(): void
    {
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => self::id('endpoint-b'), 'connection_id' => self::id('connection'),
            'host' => 'pve-b.test', 'port' => 8006, 'priority' => 20, 'enabled' => 1,
            'tls_mode' => 'system_ca', 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $store = $this->store();
        $claim = $this->claim($store);
        $subject = $store->subjects($claim, null, 10)[0];
        $store->bindSnapshotEndpoint($claim, self::id('endpoint'), $this->databaseNow());

        $this->expectException(\InvalidArgumentException::class);
        $store->stage($claim, [new ExecutorPermissionProjection(
            $subject, self::id('endpoint-b'), 1, 1, 1, true, true,
        )]);
    }

    public function testOneHundredPublicationsKeepOnlyOneRawGeneration(): void
    {
        $store = $this->store();
        for ($cycle = 0; $cycle < 100; ++$cycle) {
            if ($cycle > 0) {
                $this->makeDue();
            }
            $this->publishClaim($store, $this->claim($store));
        }
        self::assertSame('1', $this->scalarString($this->connection()->fetchOne('SELECT COUNT(*) FROM executor_permission_evidence')));
        self::assertSame('1', $this->scalarString($this->connection()->fetchOne('SELECT COUNT(*) FROM current_executor_permission_evidence')));
    }

    public function testMissingEndpointAdvancesCadenceWithoutCreatingALeaseOrThrowing(): void
    {
        $this->connection()->update('proxmox_connection_endpoints', ['enabled' => 0], [
            'connection_id' => self::id('connection'),
        ]);
        $before = $this->databaseNow();
        self::assertNull($this->store()->claimDue(self::id('worker'), $before));
        $state = $this->connection()->fetchAssociative(<<<'SQL'
SELECT next_due_at, lease_owner, last_failure_code
FROM executor_evidence_refresh_state
WHERE connection_id = :connection
SQL, ['connection' => self::id('connection')]);
        self::assertIsArray($state);
        self::assertNull($state['lease_owner']);
        self::assertSame('configuration_changed', $state['last_failure_code']);
        self::assertGreaterThanOrEqual($before->modify('+120 seconds')->format('Y-m-d H:i:s.u'), $state['next_due_at']);
        self::assertNull($this->store()->claimDue(self::id('worker-b'), $this->databaseNow()));
    }

    public function testSubjectOverflowAdvancesCadenceAndPurgesThePartialStage(): void
    {
        $this->connection()->insert('backup_targets', [
            'id' => self::id('target-b'), 'connection_id' => self::id('connection'),
            'cluster_id' => self::id('cluster'), 'storage_id' => self::id('storage'),
            'display_name' => 'Second draft target', 'status' => 'disabled', 'revision' => 1,
            'minimum_free_bytes' => '1', 'fixed_parallel_limit' => 1,
            'created_at' => self::NOW, 'updated_at' => self::NOW, 'disabled_at' => self::NOW,
        ]);
        $this->connection()->insert('backup_target_allowed_nodes', [
            'target_id' => self::id('target-b'), 'connection_id' => self::id('connection'),
            'cluster_id' => self::id('cluster'), 'node_id' => self::id('node'), 'created_at' => self::NOW,
        ]);
        $store = new DbalExecutorEvidenceRefreshStore(
            $this->connection(), new ExecutorEvidenceTokens(), 120, 90, 1,
        );
        self::assertNull($store->claimDue(self::id('worker'), $this->databaseNow()));
        self::assertSame('0', $this->scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM executor_evidence_refresh_subject_stage',
        )));
        self::assertSame('configuration_changed', $this->connection()->fetchOne(
            'SELECT last_failure_code FROM executor_evidence_refresh_state WHERE connection_id = :connection',
            ['connection' => self::id('connection')],
        ));
        self::assertNull($store->claimDue(self::id('worker-b'), $this->databaseNow()));
    }

    public function testEveryStoreBoundaryRejectsAStaleFence(): void
    {
        $store = $this->store();
        $active = $this->claim($store);
        $subject = $store->subjects($active, null, 10)[0];
        $stale = new ExecutorEvidenceRefreshClaim(
            $active->connectionId, $active->connectionRevision, $active->backupCredentialRevision,
            $active->scanCredentialRevision, $active->leaseOwner, self::id('stale-token'),
            $active->leaseFence + 1, $active->leaseExpiresAt, $active->endpoints,
        );
        $projection = new ExecutorPermissionProjection(
            $subject, self::id('endpoint'), 1, 1, 1, true, true,
        );
        $now = $this->databaseNow();
        $operations = [
            static fn () => $store->renew($stale, $now),
            static fn () => $store->bindSnapshotEndpoint($stale, self::id('endpoint'), $now),
            static fn () => $store->subjects($stale, null, 10),
            static fn () => $store->stage($stale, [$projection]),
            static fn () => $store->publish($stale, $now),
            static fn () => $store->fail($stale, \App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode::Transport, $now),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('A stale executor-evidence fence crossed a persistence boundary.');
            } catch (ExecutorEvidenceLeaseOwnershipLost) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSkipLockedDoesNotDoubleClaimALockedDueConnection(): void
    {
        $this->connection()->insert('executor_evidence_refresh_state', [
            'connection_id' => self::id('connection'), 'next_due_at' => self::NOW,
            'lease_fence' => 0, 'published_set_revision' => 0, 'updated_at' => self::NOW,
        ]);
        $this->connection()->commit();
        $first = DriverManager::getConnection($this->connection()->getParams());
        $second = DriverManager::getConnection($this->connection()->getParams());
        try {
            $first->beginTransaction();
            $first->fetchOne(
                'SELECT connection_id FROM executor_evidence_refresh_state WHERE connection_id = :connection FOR UPDATE',
                ['connection' => self::id('connection')],
            );
            $started = microtime(true);
            self::assertNull((new DbalExecutorEvidenceRefreshStore(
                $second, new ExecutorEvidenceTokens(), 120, 90, 128,
            ))->claimDue(self::id('worker-b'), $this->databaseNowOn($second)));
            self::assertLessThan(1.0, microtime(true) - $started);
        } finally {
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            $first->close();
            $second->close();
            $this->cleanupCommittedFixture();
        }
    }

    public function testBackupWorkerRolePerformsARealClaimStagePublishAndCleanup(): void
    {
        $this->connection()->commit();
        $worker = $this->runtimeWorker();
        try {
            $store = new DbalExecutorEvidenceRefreshStore(
                $worker, new ExecutorEvidenceTokens(), 120, 90, 128,
            );
            $claim = $store->claimDue(self::id('runtime-worker'), $this->databaseNowOn($worker));
            self::assertNotNull($claim);
            $configuration = (new DbalPveExecutorEvidenceConfigurationSource($worker))->load(
                $claim,
                $claim->endpoints[0],
            );
            self::assertSame($claim->connectionId, $configuration->connectionId);
            self::assertSame($claim->endpoints[0]->id, $configuration->endpointId);
            self::assertSame('pve-a.test', $configuration->host);
            self::assertSame(8006, $configuration->port);
            self::assertSame(9, $configuration->majorVersion);
            self::assertSame('backup@pve!backup', $configuration->backupTokenIdentity);
            self::assertSame(PveTlsMode::SystemCa, $configuration->tls->mode);
            self::assertSame(['value' => '[REDACTED]'], $configuration->__debugInfo());
            $this->publishClaim($store, $claim);
            self::assertSame('1', $this->scalarString($worker->fetchOne(
                'SELECT COUNT(*) FROM current_executor_permission_evidence',
            )));
            try {
                $worker->fetchOne('SELECT COUNT(*) FROM executor_permission_evidence');
                self::fail('The backup worker unexpectedly read raw executor evidence.');
            } catch (\Doctrine\DBAL\Exception) {
                self::addToAssertionCount(1);
            }
        } finally {
            $worker->close();
            $this->cleanupCommittedFixture();
        }
    }

    private function publishClaim(DbalExecutorEvidenceRefreshStore $store, ExecutorEvidenceRefreshClaim $claim): void
    {
        $subjects = $store->subjects($claim, null, 10);
        $observed = $this->databaseNow();
        $store->bindSnapshotEndpoint($claim, self::id('endpoint'), $observed);
        if ([] !== $subjects) {
            $store->stage($claim, [new ExecutorPermissionProjection(
                $subjects[0], self::id('endpoint'), 1, 1, 1, true, true,
            )]);
        }
        $store->publish($claim, $observed);
    }

    private function claim(
        DbalExecutorEvidenceRefreshStore $store,
        ?string $worker = null,
    ): ExecutorEvidenceRefreshClaim {
        $claim = $store->claimDue($worker ?? self::id('worker'), $this->databaseNow());
        self::assertNotNull($claim);
        return $claim;
    }

    private function makeDue(): void
    {
        $this->connection()->executeStatement(
            'UPDATE executor_evidence_refresh_state SET next_due_at = UTC_TIMESTAMP(6) - INTERVAL 1 MICROSECOND',
        );
    }

    private function store(int $leaseSeconds = 90): DbalExecutorEvidenceRefreshStore
    {
        return new DbalExecutorEvidenceRefreshStore(
            $this->connection(), new ExecutorEvidenceTokens(), 120, $leaseSeconds, 128,
        );
    }

    private function databaseNow(): DateTimeImmutable
    {
        $raw = $this->connection()->fetchOne('SELECT UTC_TIMESTAMP(6)');
        self::assertIsString($raw);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $raw, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        return $date;
    }

    private function databaseNowOn(Connection $connection): DateTimeImmutable
    {
        $raw = $connection->fetchOne('SELECT UTC_TIMESTAMP(6)');
        self::assertIsString($raw);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $raw, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        return $date;
    }

    private function runtimeWorker(): Connection
    {
        $password = file_get_contents('/run/secrets/mariadb_backup_worker_password');
        self::assertIsString($password);
        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => 'hoddmimir_backup_worker',
            'password' => trim($password),
        ]));
    }

    private function cleanupCommittedFixture(): void
    {
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([
                'executor_evidence_refresh_projection_stage', 'executor_evidence_refresh_subject_stage',
                'executor_permission_evidence', 'executor_evidence_refresh_state',
                'backup_target_allowed_nodes', 'backup_targets', 'pve_storages', 'pve_nodes',
                'pve_clusters', 'inventory_sync_runs', 'proxmox_capability_snapshots',
                'proxmox_credentials', 'proxmox_connection_endpoints', 'proxmox_connections',
                'collector_cycles', 'collector_schedule', 'worker_heartbeats',
            ] as $table) {
                $this->connection()->executeStatement('DELETE FROM '.$table);
            }
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function seedTarget(): void
    {
        $connection = self::id('connection');
        $run = self::id('run');
        $this->connection()->insert('proxmox_connections', [
            'id' => $connection, 'display_name' => 'Executor evidence', 'product' => 'pve',
            'enabled' => 1, 'revision' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => self::id('endpoint'), 'connection_id' => $connection, 'host' => 'pve-a.test',
            'port' => 8006, 'priority' => 10, 'enabled' => 1, 'tls_mode' => 'system_ca',
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        foreach ([['backup', 'backup'], ['collector', 'scan']] as [$purpose, $token]) {
            $this->connection()->insert('proxmox_credentials', [
                'id' => self::id($purpose.'-credential'), 'connection_id' => $connection,
                'purpose' => $purpose, 'auth_scheme' => 'api_token', 'principal' => $purpose.'@pve',
                'token_name' => $token, 'secret_envelope' => 'opaque-'.$purpose,
                'envelope_version' => 1, 'key_id' => 'test-key', 'revision' => 1,
                'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
        }
        $capabilities = '{"fixture":"executor-evidence"}';
        $this->connection()->insert('proxmox_capability_snapshots', [
            'id' => self::id('capability'), 'connection_id' => $connection, 'product' => 'pve',
            'version_major' => 9, 'version_minor' => 0, 'raw_version' => '9.0.0',
            'profile_version' => 1, 'capabilities_json' => $capabilities,
            'snapshot_hash' => hash('sha256', $capabilities, true),
            'first_observed_at' => self::NOW, 'last_observed_at' => self::NOW,
        ]);

        // Inventory run FKs are intentionally satisfied with a compact
        // committed-shape fixture; the refresh itself remains in this test TX.
        $cycle = self::id('cycle');
        $worker = self::id('collector-worker');
        $this->connection()->insert('worker_heartbeats', [
            'worker_instance_id' => $worker, 'worker_kind' => 'collector', 'status' => 'ready',
            'started_at' => self::NOW, 'heartbeat_at' => self::NOW,
            'expires_at' => '2026-07-16 11:00:00.000000', 'build_version' => 'test',
        ]);
        $this->connection()->insert('collector_schedule', [
            'schedule_name' => 'inventory', 'grid_started_at' => self::NOW, 'interval_seconds' => 120,
            'next_scan_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $this->connection()->insert('collector_cycles', [
            'cycle_token' => $cycle, 'schedule_name' => 'inventory', 'worker_instance_id' => $worker,
            'worker_kind' => 'collector', 'fencing_token' => 1, 'scheduled_for' => self::NOW,
            'started_at' => self::NOW, 'heartbeat_at' => self::NOW, 'status' => 'running',
        ]);
        $this->connection()->insert('inventory_sync_runs', [
            'id' => $run, 'cycle_token' => $cycle, 'collector_fencing_token' => 1,
            'connection_id' => $connection, 'expected_connection_revision' => 1,
            'status' => 'succeeded', 'authoritative' => 1, 'started_at' => self::NOW,
            'heartbeat_at' => self::NOW, 'finished_at' => self::NOW, 'applied_at' => self::NOW,
        ]);
        $cluster = self::id('cluster');
        $node = self::id('node');
        $storage = self::id('storage');
        $this->connection()->insert('pve_clusters', [
            'id' => $cluster, 'connection_id' => $connection, 'external_name' => 'cluster',
            'topology' => 'clustered', 'inventory_state' => 'active', 'first_seen_run_id' => $run,
            'last_seen_run_id' => $run, 'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
        ]);
        $this->connection()->insert('pve_nodes', [
            'id' => $node, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'node_name' => 'node-a', 'api_status' => 'online', 'inventory_state' => 'active',
            'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
            'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
        ]);
        $this->connection()->insert('pve_storages', [
            'id' => $storage, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'storage_name' => 'backup-a', 'storage_type' => 'dir', 'supports_backup' => 1,
            'disabled' => 0, 'content_json' => '["backup"]', 'shared' => 0,
            'inventory_state' => 'active', 'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
            'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
        ]);
        $this->connection()->insert('backup_targets', [
            'id' => self::id('target'), 'connection_id' => $connection, 'cluster_id' => $cluster,
            'storage_id' => $storage, 'display_name' => 'Draft target', 'status' => 'disabled',
            'revision' => 1, 'minimum_free_bytes' => '1', 'fixed_parallel_limit' => 1,
            'created_at' => self::NOW, 'updated_at' => self::NOW, 'disabled_at' => self::NOW,
        ]);
        $this->connection()->insert('backup_target_allowed_nodes', [
            'target_id' => self::id('target'), 'connection_id' => $connection,
            'cluster_id' => $cluster, 'node_id' => $node, 'created_at' => self::NOW,
        ]);
    }

    private static function id(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }

    private function scalarString(mixed $value): string
    {
        if (!is_int($value) && !is_string($value)) {
            self::fail('MariaDB returned a non-scalar value.');
        }

        return (string) $value;
    }
}

final class ExecutorEvidenceTokens implements QueueClaimTokenSource
{
    private int $next = 0;

    public function next(): string
    {
        return substr(hash('sha256', 'executor-evidence-token-'.++$this->next, true), 0, 16);
    }
}
