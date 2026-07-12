<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\Pbs\PbsCapacityObservation;
use App\Application\Inventory\Pbs\PbsDatastoreObservation;
use App\Application\Inventory\Pbs\PbsInventoryCommit;
use App\Application\Inventory\Pbs\PbsInventoryConflict;
use App\Application\Inventory\Pbs\PbsInventoryScope;
use App\Application\Inventory\Pbs\PbsInventoryScopeResult;
use App\Application\Inventory\Pbs\PbsServerObservation;
use App\Application\Inventory\Pve\EndpointAttemptOutcome;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Inventory\Pve\PveEndpointAttempt;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Proxmox\Pbs\PbsCapacitySemantics;
use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsMaintenanceMode;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Infrastructure\Persistence\MariaDb\DbalPbsInventoryStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class DbalPbsInventoryStoreTest extends KernelTestCase
{
    private InventoryIdentifier $connectionId;
    private InventoryIdentifier $endpointId;
    private DbalPbsInventoryStore $store;
    private int $fence = 0;
    private Connection $database;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->database = $connection;
        $this->cleanDatabase();
        $this->connectionId = self::id('pbs-connection');
        $this->endpointId = self::id('pbs-endpoint');
        $this->store = new DbalPbsInventoryStore($this->connection(), new PbsSequentialIdentifierGenerator());
        $this->insertConnection();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->cleanDatabase();
            $this->database->close();
        }
        parent::tearDown();
    }

    public function testAuthoritativeApplyPersistsLegacyBindingServerDatastoresAndS3SemanticsIdempotently(): void
    {
        [$lease, $run] = $this->startRun('first');
        $at = self::at(1);
        $result = $this->store->apply($lease, $this->commit(
            $run,
            [
                $this->storeObservation('local', PbsDatastoreBackendType::Filesystem),
                $this->storeObservation(
                    'object',
                    PbsDatastoreBackendType::S3,
                    PbsMountStatus::Mounted,
                    PbsMaintenanceMode::ReadOnly,
                ),
            ],
            [
                $this->capacity('local', PbsDatastoreBackendType::Filesystem),
                $this->capacity('object', PbsDatastoreBackendType::S3),
            ],
            observedAt: $at,
        ));

        self::assertSame('succeeded', $result->status);
        self::assertSame(
            ['identity_kind' => 'pbs_legacy_node', 'identity_value' => 'pbs-a', 'legacy_endpoint_id' => $this->endpointId->binary()],
            $this->connection()->fetchAssociative(
                'SELECT identity_kind, identity_value, legacy_endpoint_id FROM proxmox_installation_bindings',
            ),
        );
        self::assertSame(
            [
                ['datastore_name' => 'local', 'backend_type' => 'filesystem', 'allows_backup_writes' => 1],
                ['datastore_name' => 'object', 'backend_type' => 's3', 'allows_backup_writes' => 0],
            ],
            $this->connection()->fetchAllAssociative(
                'SELECT datastore_name, backend_type, allows_backup_writes FROM pbs_datastores ORDER BY datastore_name',
            ),
        );
        self::assertSame(
            ['datastore_filesystem', 'local_cache'],
            $this->connection()->fetchFirstColumn(
                'SELECT semantics FROM pbs_datastore_capacity_state ORDER BY semantics',
            ),
        );
        $idsBefore = $this->connection()->fetchAllAssociative(
            'SELECT id, datastore_name, first_seen_at FROM pbs_datastores ORDER BY datastore_name',
        );

        [$repeatLease, $repeatRun] = $this->startRun('repeat');
        $this->store->apply($repeatLease, $this->commit(
            $repeatRun,
            [
                $this->storeObservation('local', PbsDatastoreBackendType::Filesystem),
                $this->storeObservation('object', PbsDatastoreBackendType::S3, maintenance: PbsMaintenanceMode::ReadOnly),
            ],
            [
                $this->capacity('local', PbsDatastoreBackendType::Filesystem),
                $this->capacity('object', PbsDatastoreBackendType::S3),
            ],
            observedAt: self::at(2),
        ));
        self::assertSame($idsBefore, $this->connection()->fetchAllAssociative(
            'SELECT id, datastore_name, first_seen_at FROM pbs_datastores ORDER BY datastore_name',
        ));
    }

    public function testFirstPartialIsDiagnosticAndBoundPartialKeepsAbsenceAndOldCapacity(): void
    {
        [$partialLease, $partialRun] = $this->startRun('unbound-partial');
        $partial = $this->commit(
            $partialRun,
            [$this->storeObservation('local', PbsDatastoreBackendType::Filesystem)],
            [],
            datastoreStatus: InventoryScopeStatus::Partial,
            statusStatus: InventoryScopeStatus::Partial,
            observedAt: self::at(1),
        );
        $result = $this->store->apply($partialLease, $partial);
        self::assertTrue($result->diagnosticOnly);
        self::assertSame(0, $this->countRows('pbs_servers'));
        self::assertSame(0, $this->countRows('proxmox_installation_bindings'));

        [$seedLease, $seedRun] = $this->startRun('seed');
        $this->store->apply($seedLease, $this->commit(
            $seedRun,
            [
                $this->storeObservation('local', PbsDatastoreBackendType::Filesystem),
                $this->storeObservation('missing', PbsDatastoreBackendType::Filesystem),
            ],
            [
                $this->capacity('local', PbsDatastoreBackendType::Filesystem),
                $this->capacity('missing', PbsDatastoreBackendType::Filesystem),
            ],
            observedAt: self::at(2),
        ));
        $oldCapacityRun = $this->connection()->fetchOne(
            <<<'SQL'
                SELECT capacity.sync_run_id FROM pbs_datastore_capacity_state capacity
                JOIN pbs_datastores store ON store.id = capacity.datastore_id
                WHERE store.datastore_name = 'local'
                SQL,
        );

        [$boundPartialLease, $boundPartialRun] = $this->startRun('bound-partial');
        $this->store->apply($boundPartialLease, $this->commit(
            $boundPartialRun,
            [$this->storeObservation('local', PbsDatastoreBackendType::Filesystem)],
            [],
            datastoreStatus: InventoryScopeStatus::Partial,
            statusStatus: InventoryScopeStatus::Partial,
            observedAt: self::at(3),
        ));
        self::assertSame(2, $this->countRows('pbs_datastores', "inventory_state = 'active'"));
        self::assertSame($oldCapacityRun, $this->connection()->fetchOne(
            <<<'SQL'
                SELECT capacity.sync_run_id FROM pbs_datastore_capacity_state capacity
                JOIN pbs_datastores store ON store.id = capacity.datastore_id
                WHERE store.datastore_name = 'local'
                SQL,
        ));
    }

    public function testPartialBackendChangeRemovesOnlyItsIncompatibleCapacity(): void
    {
        [$seedLease, $seedRun] = $this->startRun('backend-seed');
        $this->store->apply($seedLease, $this->commit(
            $seedRun,
            [
                $this->storeObservation('changes', PbsDatastoreBackendType::Filesystem),
                $this->storeObservation('stays', PbsDatastoreBackendType::Filesystem),
            ],
            [
                $this->capacity('changes', PbsDatastoreBackendType::Filesystem),
                $this->capacity('stays', PbsDatastoreBackendType::Filesystem),
            ],
            observedAt: self::at(1),
        ));

        [$partialLease, $partialRun] = $this->startRun('backend-partial');
        $this->store->apply($partialLease, $this->commit(
            $partialRun,
            [$this->storeObservation('changes', PbsDatastoreBackendType::S3)],
            [],
            datastoreStatus: InventoryScopeStatus::Partial,
            statusStatus: InventoryScopeStatus::Failed,
            observedAt: self::at(2),
        ));

        self::assertSame('s3', $this->connection()->fetchOne(
            "SELECT backend_type FROM pbs_datastores WHERE datastore_name = 'changes'",
        ));
        self::assertSame(0, $this->capacityCount('changes'));
        self::assertSame(1, $this->capacityCount('stays'));
    }

    public function testAuthoritativeArchiveReactivationAndLegacyUpgradeKeepIdentity(): void
    {
        [$seedLease, $seedRun] = $this->startRun('archive-seed');
        $this->store->apply($seedLease, $this->commit(
            $seedRun,
            [$this->storeObservation('returns', PbsDatastoreBackendType::Filesystem)],
            [$this->capacity('returns', PbsDatastoreBackendType::Filesystem)],
            observedAt: self::at(1),
        ));
        $before = $this->connection()->fetchAssociative(
            "SELECT id, first_seen_at FROM pbs_datastores WHERE datastore_name = 'returns'",
        );

        [$archiveLease, $archiveRun] = $this->startRun('archive');
        $this->store->apply($archiveLease, $this->commit($archiveRun, [], [], observedAt: self::at(2)));
        self::assertSame('archived', $this->connection()->fetchOne(
            "SELECT inventory_state FROM pbs_datastores WHERE datastore_name = 'returns'",
        ));
        self::assertSame(0, $this->capacityCount('returns'));

        [$returnLease, $returnRun] = $this->startRun('return');
        $this->store->apply($returnLease, $this->commit(
            $returnRun,
            [$this->storeObservation('returns', PbsDatastoreBackendType::Filesystem)],
            [$this->capacity('returns', PbsDatastoreBackendType::Filesystem)],
            observedAt: self::at(3),
        ));
        self::assertSame($before, $this->connection()->fetchAssociative(
            "SELECT id, first_seen_at FROM pbs_datastores WHERE datastore_name = 'returns'",
        ));

        [$upgradeLease, $upgradeRun] = $this->startRun('upgrade');
        $this->store->apply($upgradeLease, $this->commit(
            $upgradeRun,
            [$this->storeObservation('returns', PbsDatastoreBackendType::Filesystem)],
            [$this->capacity('returns', PbsDatastoreBackendType::Filesystem)],
            binding: InstallationBinding::pbsInstance(str_repeat('a', 32)),
            observedAt: self::at(4),
        ));
        self::assertSame(
            ['identity_kind' => 'pbs_instance', 'identity_value' => str_repeat('a', 32), 'legacy_endpoint_id' => null],
            $this->connection()->fetchAssociative(
                'SELECT identity_kind, identity_value, legacy_endpoint_id FROM proxmox_installation_bindings',
            ),
        );
    }

    public function testPartialObservationCannotUpgradeLegacyBindingToInstanceIdentity(): void
    {
        [$seedLease, $seedRun] = $this->startRun('partial-upgrade-seed');
        $this->store->apply($seedLease, $this->commit(
            $seedRun,
            [$this->storeObservation('local', PbsDatastoreBackendType::Filesystem)],
            [$this->capacity('local', PbsDatastoreBackendType::Filesystem)],
        ));

        [$partialLease, $partialRun] = $this->startRun('partial-upgrade');
        try {
            $this->store->apply($partialLease, $this->commit(
                $partialRun,
                [$this->storeObservation('local', PbsDatastoreBackendType::Filesystem)],
                [],
                datastoreStatus: InventoryScopeStatus::Partial,
                statusStatus: InventoryScopeStatus::Partial,
                binding: InstallationBinding::pbsInstance(str_repeat('b', 32)),
            ));
            self::fail('A partial PBS observation must not upgrade a legacy binding.');
        } catch (PbsInventoryConflict) {
            self::addToAssertionCount(1);
        }

        self::assertSame(
            ['identity_kind' => 'pbs_legacy_node', 'identity_value' => 'pbs-a', 'legacy_endpoint_id' => $this->endpointId->binary()],
            $this->connection()->fetchAssociative(
                'SELECT identity_kind, identity_value, legacy_endpoint_id FROM proxmox_installation_bindings',
            ),
        );
        self::assertSame('running', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run',
            ['run' => $partialRun->binary()],
        ));
    }

    public function testApplyRejectsSelectedEndpointMismatchWithoutMutatingInventory(): void
    {
        [$lease, $run] = $this->startRun('endpoint-mismatch');
        $otherEndpoint = self::id('pbs-other-endpoint');
        $this->insertEndpoint($otherEndpoint, 'pbs-b.test');
        $this->connection()->update('inventory_sync_runs', [
            'endpoint_id' => $otherEndpoint->binary(),
        ], ['id' => $run->binary()]);

        try {
            $this->store->apply($lease, $this->commit(
                $run,
                [$this->storeObservation('local', PbsDatastoreBackendType::Filesystem)],
                [$this->capacity('local', PbsDatastoreBackendType::Filesystem)],
            ));
            self::fail('Apply must use the endpoint selected by the sync run.');
        } catch (PbsInventoryConflict) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $this->countRows('pbs_servers'));
        self::assertSame(0, $this->countRows('inventory_sync_scope_results'));
        self::assertSame('running', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run',
            ['run' => $run->binary()],
        ));
    }

    public function testRevisionDriftAndStaleFenceRejectBeforeAnyPbsMutation(): void
    {
        [$revisionLease, $revisionRun] = $this->startRun('revision-drift');
        $this->connection()->update('proxmox_connections', ['revision' => 2], [
            'id' => $this->connectionId->binary(),
        ]);
        try {
            $this->store->apply($revisionLease, $this->commit(
                $revisionRun,
                [$this->storeObservation('local', PbsDatastoreBackendType::Filesystem)],
                [$this->capacity('local', PbsDatastoreBackendType::Filesystem)],
            ));
            self::fail('Connection revision drift must reject PBS inventory.');
        } catch (PbsInventoryConflict $conflict) {
            self::assertTrue($conflict->connectionChanged);
        }
        self::assertSame(0, $this->countRows('pbs_servers'));
        $this->connection()->update('proxmox_connections', ['revision' => 1], [
            'id' => $this->connectionId->binary(),
        ]);

        [$staleLease, $staleRun] = $this->startRun('stale-fence');
        $this->connection()->executeStatement(
            "UPDATE collector_schedule SET lease_fencing_token = lease_fencing_token + 1 WHERE schedule_name = 'inventory'",
        );
        try {
            $this->store->apply($staleLease, $this->commit(
                $staleRun,
                [$this->storeObservation('local', PbsDatastoreBackendType::Filesystem)],
                [$this->capacity('local', PbsDatastoreBackendType::Filesystem)],
            ));
            self::fail('A stale collector fence must reject PBS inventory.');
        } catch (CollectorLeaseOwnershipLost) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->countRows('pbs_servers'));
        self::assertSame(0, $this->countRows('proxmox_installation_bindings'));
    }

    public function testRunAppliesOnlyOnceAndTwoConnectionsCommitAtMostOnce(): void
    {
        [$onceLease, $onceRun] = $this->startRun('apply-once');
        $onceCommit = $this->commit(
            $onceRun,
            [$this->storeObservation('once', PbsDatastoreBackendType::Filesystem)],
            [$this->capacity('once', PbsDatastoreBackendType::Filesystem)],
        );
        $this->store->apply($onceLease, $onceCommit);
        try {
            $this->store->apply($onceLease, $onceCommit);
            self::fail('A finalized PBS inventory run must not apply twice.');
        } catch (CollectorLeaseOwnershipLost|PbsInventoryConflict) {
            self::addToAssertionCount(1);
        }

        [$raceLease, $raceRun] = $this->startRun('apply-race');
        $raceCommit = $this->commit(
            $raceRun,
            [$this->storeObservation('race', PbsDatastoreBackendType::Filesystem)],
            [$this->capacity('race', PbsDatastoreBackendType::Filesystem)],
        );
        $results = $this->runApplyChildren($raceLease, $raceCommit, 2);
        sort($results);

        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => 'ok' === $result)));
        self::assertCount(1, array_filter(
            $results,
            static fn (string $result): bool => str_contains($result, 'CollectorLeaseOwnershipLost')
                || str_contains($result, 'PbsInventoryConflict'),
        ));
        self::assertSame('succeeded', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run',
            ['run' => $raceRun->binary()],
        ));
        self::assertSame(2, $this->countRows('pbs_datastores'));
    }

    public function testLeaseExpiryDuringScheduleLockWaitRollsBackPbsApply(): void
    {
        [$lease, $run] = $this->startRun('lease-expiry-lock');
        $commit = $this->commit(
            $run,
            [$this->storeObservation('expired', PbsDatastoreBackendType::Filesystem)],
            [$this->capacity('expired', PbsDatastoreBackendType::Filesystem)],
        );

        $this->connection()->beginTransaction();
        $this->connection()->fetchAssociative(
            "SELECT * FROM collector_schedule WHERE schedule_name = 'inventory' FOR UPDATE",
        );
        [$processId, $socket] = $this->startApplyChild($lease, $commit);
        $this->releaseApplyChild($socket);
        usleep(150_000);
        $this->connection()->executeStatement(
            <<<'SQL'
                UPDATE collector_schedule
                SET lease_acquired_at = UTC_TIMESTAMP(6) - INTERVAL 2 SECOND,
                    lease_expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND
                WHERE schedule_name = 'inventory'
                SQL,
        );
        $this->connection()->commit();

        $result = $this->finishApplyChild($processId, $socket);
        self::assertStringContainsString('CollectorLeaseOwnershipLost', $result);
        self::assertSame(0, $this->countRows('pbs_servers'));
        self::assertSame(0, $this->countRows('inventory_sync_scope_results'));
        self::assertSame('running', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run',
            ['run' => $run->binary()],
        ));
    }

    /** @return array{CollectorLease, InventoryIdentifier} */
    private function startRun(string $label): array
    {
        $lease = $this->lease($label);
        $run = self::id('run-'.$label);
        $this->store->beginRun($lease, new PveSyncRunStart($run, $this->connectionId, 1, self::at(0)));
        $this->store->recordEndpointAttempt($lease, new PveEndpointAttempt(
            self::id('attempt-'.$label),
            $run,
            $this->connectionId,
            $this->endpointId,
            1,
            EndpointAttemptOutcome::Selected,
            null,
            self::at(0),
            self::at(0),
        ));
        return [$lease, $run];
    }

    /**
     * @param list<PbsDatastoreObservation> $stores
     * @param list<PbsCapacityObservation>  $capacities
     */
    private function commit(
        InventoryIdentifier $run,
        array $stores,
        array $capacities,
        InventoryScopeStatus $systemStatus = InventoryScopeStatus::Complete,
        InventoryScopeStatus $datastoreStatus = InventoryScopeStatus::Complete,
        InventoryScopeStatus $statusStatus = InventoryScopeStatus::Complete,
        ?InstallationBinding $binding = null,
        ?DateTimeImmutable $observedAt = null,
    ): PbsInventoryCommit {
        return new PbsInventoryCommit(
            $run,
            $this->connectionId,
            $this->endpointId,
            1,
            $binding ?? InstallationBinding::pbsLegacyNode(
                'pbs-a',
                new EndpointId($this->endpointId->binary()),
            ),
            new PbsInventoryScopeResult(PbsInventoryScope::System, '@installation', $systemStatus),
            new PbsInventoryScopeResult(PbsInventoryScope::Datastores, '@installation', $datastoreStatus),
            array_map(
                static fn (PbsDatastoreObservation $store): PbsInventoryScopeResult => new PbsInventoryScopeResult(
                    PbsInventoryScope::DatastoreStatus,
                    $store->id,
                    $statusStatus,
                ),
                $stores,
            ),
            new PbsServerObservation(
                'pbs-a',
                new PbsVersion(null !== $binding ? 4 : 3, null !== $binding ? 2 : 4, 0, null !== $binding ? '4.2.0' : '3.4.0', '1', 'repo'),
                new PbsNodeStatus('pbs-a', 100, 1000, 200, 1000, 200, 800),
            ),
            $stores,
            $capacities,
            $observedAt ?? self::at(1),
        );
    }

    private function storeObservation(
        string $id,
        PbsDatastoreBackendType $backend,
        PbsMountStatus $mount = PbsMountStatus::Mounted,
        ?PbsMaintenanceMode $maintenance = null,
    ): PbsDatastoreObservation {
        return new PbsDatastoreObservation(
            $id,
            $backend,
            $mount,
            $maintenance,
            $mount->isAvailable() && null === $maintenance,
        );
    }

    private function capacity(string $id, PbsDatastoreBackendType $backend): PbsCapacityObservation
    {
        return new PbsCapacityObservation(
            $id,
            $backend,
            PbsDatastoreBackendType::S3 === $backend
                ? PbsCapacitySemantics::LocalCache
                : PbsCapacitySemantics::DatastoreFilesystem,
            1000,
            200,
            800,
        );
    }

    private function insertConnection(): void
    {
        $at = $this->format(self::at(0));
        $this->connection()->insert('proxmox_connections', [
            'id' => $this->connectionId->binary(),
            'display_name' => 'PBS integration '.bin2hex($this->connectionId->binary()),
            'product' => 'pbs',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $this->endpointId->binary(),
            'connection_id' => $this->connectionId->binary(),
            'host' => 'pbs-a.test',
            'port' => 8007,
            'priority' => 100,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function insertEndpoint(InventoryIdentifier $endpoint, string $host): void
    {
        $at = $this->format(self::at(0));
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $endpoint->binary(),
            'connection_id' => $this->connectionId->binary(),
            'host' => $host,
            'port' => 8007,
            'priority' => 200,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function lease(string $label): CollectorLease
    {
        ++$this->fence;
        $now = $this->databaseNow();
        $expires = $now->modify('+1 hour');
        $worker = new CollectorWorkerId(self::bytes('pbs-worker'));
        $token = new CollectorCycleToken(self::bytes('pbs-cycle-'.$label));
        $this->connection()->executeStatement(
            "UPDATE collector_cycles SET status = 'succeeded', finished_at = :now, duration_ms = 0 WHERE status = 'running'",
            ['now' => $this->format($now)],
        );
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO worker_heartbeats (
                    worker_instance_id, worker_kind, status, started_at, heartbeat_at, expires_at, build_version
                ) VALUES (:worker, 'collector', 'ready', :now, :now, :expires, 'integration-test')
                ON DUPLICATE KEY UPDATE heartbeat_at = VALUES(heartbeat_at), expires_at = VALUES(expires_at)
                SQL,
            ['worker' => $worker->bytes, 'now' => $this->format($now), 'expires' => $this->format($expires)],
        );
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO collector_schedule (
                    schedule_name, grid_started_at, interval_seconds, next_scan_at, lease_owner, lease_token,
                    lease_fencing_token, lease_acquired_at, lease_expires_at, last_cycle_started_at, updated_at
                ) VALUES ('inventory', :now, 120, :now, :worker, :token, :fence, :now, :expires, :now, :now)
                ON DUPLICATE KEY UPDATE lease_owner = VALUES(lease_owner), lease_token = VALUES(lease_token),
                    lease_fencing_token = VALUES(lease_fencing_token), lease_acquired_at = VALUES(lease_acquired_at),
                    lease_expires_at = VALUES(lease_expires_at), updated_at = VALUES(updated_at)
                SQL,
            [
                'now' => $this->format($now), 'worker' => $worker->bytes, 'token' => $token->binary(),
                'fence' => $this->fence, 'expires' => $this->format($expires),
            ],
        );
        $this->connection()->insert('collector_cycles', [
            'cycle_token' => $token->binary(), 'schedule_name' => 'inventory',
            'worker_instance_id' => $worker->bytes, 'worker_kind' => 'collector',
            'fencing_token' => $this->fence, 'scheduled_for' => $this->format($now),
            'started_at' => $this->format($now), 'heartbeat_at' => $this->format($now), 'status' => 'running',
        ]);
        return new CollectorLease($worker, $token, $this->fence, $expires);
    }

    private function countRows(string $table, string $where = '1=1'): int
    {
        $value = $this->connection()->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE '.$where);
        self::assertTrue(is_int($value) || (is_string($value) && ctype_digit($value)));
        return (int) $value;
    }

    private function connection(): Connection
    {
        return $this->database;
    }

    private function capacityCount(string $store): int
    {
        $value = $this->connection()->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM pbs_datastore_capacity_state capacity
                JOIN pbs_datastores datastore ON datastore.id = capacity.datastore_id
                WHERE datastore.datastore_name = :store
                SQL,
            ['store' => $store],
        );
        self::assertTrue(is_int($value) || (is_string($value) && ctype_digit($value)));
        return (int) $value;
    }

    private function databaseNow(): DateTimeImmutable
    {
        $value = $this->connection()->fetchOne("SELECT DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%d %H:%i:%s.%f')");
        self::assertIsString($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        return $date;
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function at(int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-07-11T00:00:00Z'))->modify('+'.$seconds.' seconds');
    }

    private static function id(string $label): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes($label));
    }

    private static function bytes(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }

    /** @return list<string> */
    private function runApplyChildren(CollectorLease $lease, PbsInventoryCommit $commit, int $count): array
    {
        /** @var list<array{int, resource}> $children */
        $children = [];
        for ($index = 0; $index < $count; ++$index) {
            $children[] = $this->startApplyChild($lease, $commit);
        }
        foreach ($children as [, $socket]) {
            $this->releaseApplyChild($socket);
        }

        $results = [];
        foreach ($children as [$processId, $socket]) {
            $results[] = $this->finishApplyChild($processId, $socket);
        }
        return $results;
    }

    /** @return array{int, resource} */
    private function startApplyChild(CollectorLease $lease, PbsInventoryCommit $commit): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $sockets) {
            throw new \RuntimeException('Could not create the PBS integration-test process socket.');
        }
        [$parentSocket, $childSocket] = $sockets;
        $parameters = $this->connection()->getParams();
        $processId = pcntl_fork();
        if (-1 === $processId) {
            throw new \RuntimeException('Could not fork the PBS integration-test apply process.');
        }
        if (0 === $processId) {
            fclose($parentSocket);
            $signal = fread($childSocket, 1);
            if ('1' !== $signal) {
                fwrite($childSocket, 'test_barrier_failed');
                fclose($childSocket);
                exit(2);
            }
            $childConnection = DriverManager::getConnection($parameters);
            try {
                $childStore = new DbalPbsInventoryStore($childConnection, new PbsSequentialIdentifierGenerator());
                $childStore->apply($lease, $commit);
                fwrite($childSocket, 'ok');
            } catch (Throwable $exception) {
                fwrite($childSocket, $exception::class);
            } finally {
                $childConnection->close();
                fclose($childSocket);
            }
            pcntl_exec('/bin/true');
            posix_kill(posix_getpid(), SIGKILL);
            exit(3);
        }

        fclose($childSocket);
        stream_set_timeout($parentSocket, 15);
        return [$processId, $parentSocket];
    }

    /** @param resource $socket */
    private function releaseApplyChild($socket): void
    {
        if (1 !== fwrite($socket, '1')) {
            throw new \RuntimeException('Could not release the PBS integration-test apply process.');
        }
    }

    /** @param resource $socket */
    private function finishApplyChild(int $processId, $socket): string
    {
        $result = stream_get_contents($socket);
        fclose($socket);
        $status = 0;
        if ($processId !== pcntl_waitpid($processId, $status)) {
            throw new \RuntimeException('Could not wait for the PBS integration-test apply process.');
        }
        if (!is_int($status)) {
            throw new \RuntimeException('The PBS integration-test apply process returned an invalid wait status.');
        }
        if (!pcntl_wifexited($status) || 0 !== pcntl_wexitstatus($status)) {
            throw new \RuntimeException('The PBS integration-test apply process failed unexpectedly.');
        }
        if (false === $result || '' === $result) {
            throw new \RuntimeException('The PBS integration-test apply process returned no result.');
        }
        return $result;
    }

    private function cleanDatabase(): void
    {
        foreach ([
            'pbs_datastore_capacity_state',
            'pbs_datastores',
            'pbs_server_status',
            'pbs_servers',
            'pve_node_storage_state',
            'pve_storages',
            'guest_placements',
            'guests',
            'pve_nodes',
            'pve_clusters',
            'inventory_sync_failures',
            'inventory_sync_endpoint_attempts',
            'inventory_sync_scope_results',
            'proxmox_installation_bindings',
            'inventory_sync_runs',
            'proxmox_capability_snapshots',
            'collector_credentials',
            'proxmox_credentials',
            'proxmox_connection_endpoints',
            'proxmox_connections',
            'collector_cycles',
            'collector_schedule',
            'worker_heartbeats',
        ] as $table) {
            $this->connection()->executeStatement('DELETE FROM '.$table);
        }
    }
}

final class PbsSequentialIdentifierGenerator implements InventoryIdentifierGenerator
{
    private int $next = 0;
    public function generate(): InventoryIdentifier
    {
        return new InventoryIdentifier(substr(hash('sha256', 'pbs-generated-'.++$this->next, true), 0, 16));
    }
}
