<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\Pve\EndpointAttemptOutcome;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Inventory\Pve\PveCoreBindingKind;
use App\Application\Inventory\Pve\PveCoreInstallationBinding;
use App\Application\Inventory\Pve\PveCoreInventoryCommit;
use App\Application\Inventory\Pve\PveCoreInventoryConflict;
use App\Application\Inventory\Pve\PveCoreScope;
use App\Application\Inventory\Pve\PveCoreScopeResult;
use App\Application\Inventory\Pve\PveInventoryCommit;
use App\Application\Inventory\Pve\PveNodeStorageScopeResult;
use App\Application\Inventory\Pve\PveNodeStorageStateObservation;
use App\Application\Inventory\Pve\PveStorageCapacityStatus;
use App\Application\Inventory\Pve\PveStorageObservation;
use App\Application\Inventory\Pve\PveEndpointAttempt;
use App\Application\Inventory\Pve\PveGuestObservation;
use App\Application\Inventory\Pve\PveNodeObservation;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Inventory\Pve\PveSyncRunFailure;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PvePbsStorageMapping;
use App\Infrastructure\Persistence\MariaDb\DbalCollectorScheduleStore;
use App\Infrastructure\Persistence\MariaDb\DbalPveCoreInventoryStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class DbalPveCoreInventoryStoreTest extends KernelTestCase
{
    private const string NOW = '2026-07-11T00:00:00.000000Z';

    private InventoryIdentifier $connectionId;
    private InventoryIdentifier $primaryEndpointId;
    private InventoryIdentifier $secondaryEndpointId;
    private SequentialInventoryIdentifierGenerator $identifierGenerator;
    private DbalPveCoreInventoryStore $store;
    private int $nextFence = 0;
    private Connection $database;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->database = $connection;
        $this->cleanDatabase();

        $this->connectionId = self::id('connection');
        $this->primaryEndpointId = self::id('endpoint-primary');
        $this->secondaryEndpointId = self::id('endpoint-secondary');
        $this->identifierGenerator = new SequentialInventoryIdentifierGenerator();
        $this->store = new DbalPveCoreInventoryStore($this->connection(), $this->identifierGenerator);
        $this->insertConnection($this->connectionId, $this->primaryEndpointId, $this->secondaryEndpointId);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->connection()->executeStatement('DROP TRIGGER IF EXISTS hoddmimir_test_pve_core_rollback');
            $this->connection()->executeStatement('DROP TRIGGER IF EXISTS hoddmimir_test_pve_storage_rollback');
            $this->cleanDatabase();
            $this->database->close();
        }

        parent::tearDown();
    }

    public function testFirstAuthoritativeApplyBindsInstallationAndKeepsIdsAndFirstSeenStable(): void
    {
        [$firstLease, $firstRun] = $this->startSelectedRun('first');
        $firstCommit = $this->commit(
            $firstRun,
            ['node-a', 'node-b'],
            [
                $this->guest(PveGuestType::Qemu, 100, 'node-a', 'qemu-100'),
                $this->guest(PveGuestType::Lxc, 100, 'node-b', 'lxc-100'),
            ],
            observedAt: self::at(1),
        );

        $firstResult = $this->store->apply($firstLease, $firstCommit);

        self::assertSame('succeeded', $firstResult->status);
        self::assertSame(5, $firstResult->created);
        self::assertSame(0, $firstResult->updated);
        self::assertSame(0, $firstResult->archived);
        self::assertFalse($firstResult->diagnosticOnly);
        self::assertSame(
            ['product' => 'pve', 'identity_kind' => 'pve_cluster', 'identity_value' => 'cluster-alpha'],
            $this->connection()->fetchAssociative(
                'SELECT product, identity_kind, identity_value FROM proxmox_installation_bindings WHERE connection_id = :id',
                ['id' => $this->connectionId->binary()],
            ),
        );

        $clusterBefore = $this->rowByNaturalKey('pve_clusters', 'connection_id = :connection_id', [
            'connection_id' => $this->connectionId->binary(),
        ]);
        $nodesBefore = $this->rowsByNaturalKey(
            'SELECT id, node_name, first_seen_at FROM pve_nodes WHERE connection_id = :connection_id ORDER BY node_name',
        );
        $guestsBefore = $this->rowsByNaturalKey(
            'SELECT id, guest_type, vmid, first_seen_at FROM guests WHERE connection_id = :connection_id ORDER BY guest_type, vmid',
        );
        self::assertCount(2, $guestsBefore);
        self::assertSame(['lxc', 'qemu'], array_column($guestsBefore, 'guest_type'));
        $vmids = [];
        foreach (array_column($guestsBefore, 'vmid') as $vmid) {
            self::assertTrue(is_int($vmid) || is_string($vmid));
            $vmids[] = (string) $vmid;
        }
        self::assertSame(['100', '100'], $vmids);

        [$secondLease, $secondRun] = $this->startSelectedRun('second');
        $secondResult = $this->store->apply($secondLease, $this->commit(
            $secondRun,
            ['node-a', 'node-b'],
            [
                $this->guest(PveGuestType::Qemu, 100, 'node-b', 'qemu-100-renamed'),
                $this->guest(PveGuestType::Lxc, 100, 'node-b', 'lxc-100'),
            ],
            observedAt: self::at(2),
        ));

        self::assertSame(0, $secondResult->created);
        self::assertSame(5, $secondResult->updated);
        self::assertSame(0, $secondResult->archived);
        $clusterAfter = $this->rowByNaturalKey('pve_clusters', 'connection_id = :connection_id', [
            'connection_id' => $this->connectionId->binary(),
        ]);
        $nodesAfter = $this->rowsByNaturalKey(
            'SELECT id, node_name, first_seen_at FROM pve_nodes WHERE connection_id = :connection_id ORDER BY node_name',
        );
        $guestsAfter = $this->rowsByNaturalKey(
            'SELECT id, guest_type, vmid, first_seen_at FROM guests WHERE connection_id = :connection_id ORDER BY guest_type, vmid',
        );
        self::assertSame($clusterBefore['id'], $clusterAfter['id']);
        self::assertSame($clusterBefore['first_seen_at'], $clusterAfter['first_seen_at']);
        self::assertSame($nodesBefore, $nodesAfter);
        self::assertSame($guestsBefore, $guestsAfter);
        self::assertSame(
            'node-b',
            $this->connection()->fetchOne(
                <<<'SQL'
                    SELECT n.node_name
                    FROM guest_placements AS p
                    JOIN guests AS g ON g.id = p.guest_id
                    JOIN pve_nodes AS n ON n.id = p.node_id
                    WHERE g.connection_id = :connection_id AND g.guest_type = 'qemu' AND g.vmid = 100
                    SQL,
                ['connection_id' => $this->connectionId->binary()],
            ),
        );
        self::assertSame(2, $this->databaseInteger(
            <<<'SQL'
                SELECT p.placement_revision
                FROM guest_placements AS p
                JOIN guests AS g ON g.id = p.guest_id
                WHERE g.connection_id = :connection_id AND g.guest_type = 'qemu' AND g.vmid = 100
                SQL,
            ['connection_id' => $this->connectionId->binary()],
        ));
        self::assertSame($secondRun->binary(), $this->binaryColumn(
            "SELECT last_verified_run_id FROM proxmox_installation_bindings WHERE connection_id = :connection_id",
        ));
    }

    public function testPlacementRevisionAndRawDiskWriteStateFollowOnlyObservedFacts(): void
    {
        [$firstLease, $firstRun] = $this->startSelectedRun('guest-state-first');
        $this->store->apply($firstLease, $this->commit(
            $firstRun,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'guest', 100)],
            observedAt: self::at(1),
        ));

        [$sameLease, $sameRun] = $this->startSelectedRun('guest-state-same');
        $this->store->apply($sameLease, $this->commit(
            $sameRun,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'guest', 90)],
            observedAt: self::at(2),
            storageScope: InventoryScopeStatus::Partial,
        ));

        $guestId = $this->binaryColumn(
            "SELECT id FROM guests WHERE connection_id = :connection_id AND guest_type = 'qemu' AND vmid = 100",
        );
        $placement = $this->rowByNaturalKey('guest_placements', 'guest_id = :guest_id', ['guest_id' => $guestId]);
        self::assertDatabaseNumericValue(1, $placement['placement_revision']);
        self::assertSame('2026-07-11 00:00:02.000000', $placement['observed_at']);
        $writeState = $this->rowByNaturalKey('guest_write_states', 'guest_id = :guest_id', ['guest_id' => $guestId]);
        self::assertDatabaseNumericValue(90, $writeState['diskwrite_bytes']);
        self::assertSame('2026-07-11 00:00:02.000000', $writeState['observed_at']);
        self::assertSame($sameRun->binary(), $writeState['authoritative_sync_run_id']);

        [$partialLease, $partialRun] = $this->startSelectedRun('guest-state-partial');
        $this->store->apply($partialLease, $this->commit(
            $partialRun,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'guest', 200)],
            guests: InventoryScopeStatus::Partial,
            observedAt: self::at(3),
        ));
        $writeState = $this->rowByNaturalKey('guest_write_states', 'guest_id = :guest_id', ['guest_id' => $guestId]);
        self::assertDatabaseNumericValue(90, $writeState['diskwrite_bytes']);
        self::assertSame($sameRun->binary(), $writeState['authoritative_sync_run_id']);

        [$moveLease, $moveRun] = $this->startSelectedRun('guest-state-move');
        $this->store->apply($moveLease, $this->commit(
            $moveRun,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-b', 'guest')],
            observedAt: self::at(4),
        ));
        $placement = $this->rowByNaturalKey('guest_placements', 'guest_id = :guest_id', ['guest_id' => $guestId]);
        self::assertDatabaseNumericValue(2, $placement['placement_revision']);
        $writeState = $this->rowByNaturalKey('guest_write_states', 'guest_id = :guest_id', ['guest_id' => $guestId]);
        self::assertDatabaseNumericValue(90, $writeState['diskwrite_bytes']);
        self::assertSame('2026-07-11 00:00:02.000000', $writeState['observed_at']);

        [$repeatLease, $repeatRun] = $this->startSelectedRun('guest-state-repeat');
        $this->store->apply($repeatLease, $this->commit(
            $repeatRun,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-b', 'guest', 300)],
            observedAt: self::at(5),
        ));
        self::assertSame(2, $this->databaseInteger(
            'SELECT placement_revision FROM guest_placements WHERE guest_id = :guest_id',
            ['guest_id' => $guestId],
        ));
        self::assertSame(300, $this->databaseInteger(
            'SELECT diskwrite_bytes FROM guest_write_states WHERE guest_id = :guest_id',
            ['guest_id' => $guestId],
        ));
    }

    public function testCompositeApplyPersistsStorageMappingsDisabledDefinitionsAndCapacityAtomically(): void
    {
        [$lease, $run] = $this->startSelectedRun('storage-first');
        $at = self::at(1);
        $result = $this->store->apply($lease, $this->commit(
            $run,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'guest')],
            observedAt: $at,
            storages: [
                $this->storage('disabled-backup', true, null, $at),
                $this->storage('local-backup', false, null, $at),
                $this->storage('pbs-backup', false, new PvePbsStorageMapping(
                    'pbs.example.test',
                    8007,
                    'primary',
                    'tenant/a',
                ), $at),
            ],
            storageStates: [
                $this->storageState('node-a', 'local-backup', PveStorageCapacityStatus::Measured, $at, 1000, 400, 600),
                $this->storageState('node-b', 'local-backup', PveStorageCapacityStatus::Unavailable, $at),
                $this->storageState('node-a', 'pbs-backup', PveStorageCapacityStatus::Invalid, $at),
                $this->storageState('node-b', 'pbs-backup', PveStorageCapacityStatus::Measured, $at, 2000, 500, 1500),
            ],
        ));

        self::assertSame('succeeded', $result->status);
        self::assertSame(3, $this->databaseInteger(
            'SELECT COUNT(*) FROM pve_storages WHERE connection_id = :connection_id',
        ));
        self::assertSame(0, $this->databaseInteger(
            "SELECT COUNT(*) FROM pve_node_storage_state AS state JOIN pve_storages AS storage ON storage.id = state.storage_id WHERE storage.storage_name = 'disabled-backup'",
        ));
        self::assertSame(
            [
                'server' => 'pbs.example.test',
                'port' => '8007',
                'datastore' => 'primary',
                'namespace' => 'tenant/a',
            ],
            $this->connection()->fetchAssociative(
                'SELECT server, CAST(port AS CHAR) AS port, datastore, namespace FROM pve_storage_pbs_mappings',
            ),
        );
        self::assertSame(
            [
                ['capacity_status' => 'invalid', 'total_bytes' => null, 'used_bytes' => null, 'available_bytes' => null],
                ['capacity_status' => 'measured', 'total_bytes' => '1000', 'used_bytes' => '400', 'available_bytes' => '600'],
                ['capacity_status' => 'measured', 'total_bytes' => '2000', 'used_bytes' => '500', 'available_bytes' => '1500'],
                ['capacity_status' => 'unavailable', 'total_bytes' => null, 'used_bytes' => null, 'available_bytes' => null],
            ],
            $this->connection()->fetchAllAssociative(
                <<<'SQL'
                    SELECT capacity_status, CAST(total_bytes AS CHAR) AS total_bytes,
                           CAST(used_bytes AS CHAR) AS used_bytes, CAST(available_bytes AS CHAR) AS available_bytes
                    FROM pve_node_storage_state
                    ORDER BY capacity_status, total_bytes
                    SQL,
            ),
        );
        self::assertSame('3', $this->connection()->fetchOne(
            'SELECT CAST(storages_seen AS CHAR) FROM inventory_sync_runs WHERE id = :id',
            ['id' => $run->binary()],
        ));

        $before = $this->connection()->fetchAllAssociative(
            'SELECT id, storage_name, first_seen_at FROM pve_storages ORDER BY storage_name',
        );
        [$repeatLease, $repeatRun] = $this->startSelectedRun('storage-repeat');
        $repeatAt = self::at(2);
        $this->store->apply($repeatLease, $this->commit(
            $repeatRun,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'guest')],
            observedAt: $repeatAt,
            storages: [
                $this->storage('disabled-backup', true, null, $repeatAt),
                $this->storage('local-backup', false, null, $repeatAt),
                $this->storage('pbs-backup', false, new PvePbsStorageMapping('pbs-2.example.test', 8008, 'secondary', null), $repeatAt),
            ],
            storageStates: [
                $this->storageState('node-a', 'local-backup', PveStorageCapacityStatus::Measured, $repeatAt, 1000, 400, 600),
                $this->storageState('node-b', 'local-backup', PveStorageCapacityStatus::Unavailable, $repeatAt),
                $this->storageState('node-a', 'pbs-backup', PveStorageCapacityStatus::Invalid, $repeatAt),
                $this->storageState('node-b', 'pbs-backup', PveStorageCapacityStatus::Measured, $repeatAt, 2000, 500, 1500),
            ],
        ));
        self::assertSame($before, $this->connection()->fetchAllAssociative(
            'SELECT id, storage_name, first_seen_at FROM pve_storages ORDER BY storage_name',
        ));
        self::assertSame('pbs-2.example.test', $this->connection()->fetchOne(
            'SELECT server FROM pve_storage_pbs_mappings',
        ));
    }

    public function testPartialStorageApplyRetainsAbsenceAndFailClosedReplacesMeasuredCapacity(): void
    {
        [$seedLease, $seedRun] = $this->startSelectedRun('storage-partial-seed');
        $at = self::at(1);
        $this->store->apply($seedLease, $this->commit(
            $seedRun,
            ['node-a'],
            [],
            observedAt: $at,
            storages: [$this->storage('kept', false, null, $at), $this->storage('missing', false, null, $at)],
            storageStates: [
                $this->storageState('node-a', 'kept', PveStorageCapacityStatus::Measured, $at, 100, 20, 80),
                $this->storageState('node-a', 'missing', PveStorageCapacityStatus::Measured, $at, 100, 10, 90),
            ],
        ));

        [$partialLease, $partialRun] = $this->startSelectedRun('storage-partial');
        $partialAt = self::at(2);
        $result = $this->store->apply($partialLease, $this->commit(
            $partialRun,
            ['node-a'],
            [],
            observedAt: $partialAt,
            storageScope: InventoryScopeStatus::Partial,
            nodeStorageScope: InventoryScopeStatus::Partial,
            storages: [$this->storage('kept', false, null, $partialAt)],
            storageStates: [$this->storageState('node-a', 'kept', PveStorageCapacityStatus::Unavailable, $partialAt)],
        ));

        self::assertSame('partial', $result->status);
        self::assertSame(0, $result->archived);
        self::assertSame(2, $this->databaseInteger(
            "SELECT COUNT(*) FROM pve_storages WHERE inventory_state = 'active'",
        ));
        self::assertSame(
            ['capacity_status' => 'unavailable', 'total_bytes' => null],
            $this->connection()->fetchAssociative(
                <<<'SQL'
                    SELECT state.capacity_status, CAST(state.total_bytes AS CHAR) AS total_bytes
                    FROM pve_node_storage_state AS state
                    JOIN pve_storages AS storage ON storage.id = state.storage_id
                    WHERE storage.storage_name = 'kept'
                    SQL,
            ),
        );
        self::assertSame('measured', $this->connection()->fetchOne(
            <<<'SQL'
                SELECT state.capacity_status FROM pve_node_storage_state AS state
                JOIN pve_storages AS storage ON storage.id = state.storage_id
                WHERE storage.storage_name = 'missing'
                SQL,
        ));

        [$disabledLease, $disabledRun] = $this->startSelectedRun('storage-partial-disabled');
        $disabledAt = self::at(3);
        $disabledResult = $this->store->apply($disabledLease, $this->commit(
            $disabledRun,
            ['node-a'],
            [],
            observedAt: $disabledAt,
            storageScope: InventoryScopeStatus::Partial,
            nodeStorageScope: InventoryScopeStatus::Partial,
            storages: [$this->storage('kept', true, null, $disabledAt)],
            storageStates: [],
        ));

        self::assertSame('partial', $disabledResult->status);
        self::assertSame('1', $this->connection()->fetchOne(
            "SELECT CAST(disabled AS CHAR) FROM pve_storages WHERE storage_name = 'kept'",
        ));
        self::assertSame(0, $this->databaseInteger(
            <<<'SQL'
                SELECT COUNT(*)
                FROM pve_node_storage_state AS state
                JOIN pve_storages AS storage ON storage.id = state.storage_id
                WHERE storage.storage_name = 'kept'
                SQL,
        ));
        self::assertSame('measured', $this->connection()->fetchOne(
            <<<'SQL'
                SELECT state.capacity_status FROM pve_node_storage_state AS state
                JOIN pve_storages AS storage ON storage.id = state.storage_id
                WHERE storage.storage_name = 'missing'
                SQL,
        ));
    }

    public function testAuthoritativeStorageArchiveAndReactivationKeepIdentityAndFirstSeen(): void
    {
        [$seedLease, $seedRun] = $this->startSelectedRun('storage-archive-seed');
        $firstAt = self::at(1);
        $mapping = new PvePbsStorageMapping('pbs.example.test', 8007, 'primary', null);
        $this->store->apply($seedLease, $this->commit(
            $seedRun,
            ['node-a'],
            [],
            observedAt: $firstAt,
            storages: [$this->storage('stays', false, null, $firstAt), $this->storage('returns', false, $mapping, $firstAt)],
            storageStates: [
                $this->storageState('node-a', 'stays', PveStorageCapacityStatus::Unavailable, $firstAt),
                $this->storageState('node-a', 'returns', PveStorageCapacityStatus::Measured, $firstAt, 100, 10, 90),
            ],
        ));
        $before = $this->rowByNaturalKey('pve_storages', 'storage_name = :name', ['name' => 'returns']);

        [$archiveLease, $archiveRun] = $this->startSelectedRun('storage-archive');
        $archiveAt = self::at(2);
        $archiveResult = $this->store->apply($archiveLease, $this->commit(
            $archiveRun,
            ['node-a'],
            [],
            observedAt: $archiveAt,
            storages: [$this->storage('stays', false, null, $archiveAt)],
            storageStates: [$this->storageState('node-a', 'stays', PveStorageCapacityStatus::Unavailable, $archiveAt)],
        ));
        self::assertSame(1, $archiveResult->archived);
        $archived = $this->rowById('pve_storages', $before['id']);
        self::assertSame('archived', $archived['inventory_state']);
        self::assertSame('2026-07-11 00:00:02.000000', $archived['archived_at']);
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_storage_pbs_mappings'));
        self::assertSame(0, $this->databaseInteger(
            'SELECT COUNT(*) FROM pve_node_storage_state WHERE storage_id = :storage_id',
            ['storage_id' => $before['id']],
        ));

        [$returnLease, $returnRun] = $this->startSelectedRun('storage-return');
        $returnAt = self::at(3);
        $this->store->apply($returnLease, $this->commit(
            $returnRun,
            ['node-a'],
            [],
            observedAt: $returnAt,
            storages: [$this->storage('stays', false, null, $returnAt), $this->storage('returns', false, $mapping, $returnAt)],
            storageStates: [
                $this->storageState('node-a', 'stays', PveStorageCapacityStatus::Unavailable, $returnAt),
                $this->storageState('node-a', 'returns', PveStorageCapacityStatus::Measured, $returnAt, 100, 10, 90),
            ],
        ));
        $returned = $this->rowById('pve_storages', $before['id']);
        self::assertSame('active', $returned['inventory_state']);
        self::assertNull($returned['archived_at']);
        self::assertSame($before['first_seen_at'], $returned['first_seen_at']);
        self::assertSame(1, $this->databaseInteger('SELECT COUNT(*) FROM pve_storage_pbs_mappings'));
        self::assertSame(1, $this->databaseInteger(
            'SELECT COUNT(*) FROM pve_node_storage_state WHERE storage_id = :storage_id',
            ['storage_id' => $before['id']],
        ));
    }

    public function testStorageMidWriteFailureRollsBackCoreStorageMappingAndRunFinalization(): void
    {
        [$lease, $run] = $this->startSelectedRun('storage-rollback');
        $this->connection()->executeStatement('DROP TRIGGER IF EXISTS hoddmimir_test_pve_storage_rollback');
        $this->connection()->executeStatement(<<<'SQL'
            CREATE TRIGGER hoddmimir_test_pve_storage_rollback
            BEFORE INSERT ON pve_node_storage_state
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'intentional storage rollback'
            SQL);
        try {
            $at = self::at(1);
            try {
                $this->store->apply($lease, $this->commit(
                    $run,
                    ['node-a'],
                    [],
                    observedAt: $at,
                    storages: [$this->storage('pbs-backup', false, new PvePbsStorageMapping('pbs.test', 8007, 'store', null), $at)],
                    storageStates: [$this->storageState('node-a', 'pbs-backup', PveStorageCapacityStatus::Unavailable, $at)],
                ));
                self::fail('The storage trigger must interrupt the composite write.');
            } catch (Throwable $failure) {
                self::assertStringContainsString('intentional storage rollback', $failure->getMessage());
            }
            foreach (['proxmox_installation_bindings', 'pve_clusters', 'pve_nodes', 'pve_storages', 'pve_storage_pbs_mappings', 'pve_node_storage_state', 'inventory_sync_scope_results'] as $table) {
                self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM '.$table), $table);
            }
            self::assertSame(
                ['status' => 'running', 'applied_at' => null],
                $this->connection()->fetchAssociative(
                    'SELECT status, applied_at FROM inventory_sync_runs WHERE id = :id',
                    ['id' => $run->binary()],
                ),
            );
        } finally {
            $this->connection()->executeStatement('DROP TRIGGER IF EXISTS hoddmimir_test_pve_storage_rollback');
        }
    }

    public function testGlobalPartialRetainsEveryMissingCoreObjectEvenWithCompleteTopology(): void
    {
        [$fullLease, $fullRun] = $this->startSelectedRun('partial-seed');
        $this->store->apply($fullLease, $this->commit(
            $fullRun,
            ['node-a', 'node-b'],
            [
                $this->guest(PveGuestType::Qemu, 100, 'node-a', 'kept-observed'),
                $this->guest(PveGuestType::Qemu, 101, 'node-b', 'kept-unobserved'),
            ],
            observedAt: self::at(1),
        ));

        [$partialLease, $partialRun] = $this->startSelectedRun('partial');
        $result = $this->store->apply($partialLease, $this->commit(
            $partialRun,
            ['node-a'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'updated-positive-observation')],
            topology: InventoryScopeStatus::Complete,
            guests: InventoryScopeStatus::Partial,
            observedAt: self::at(2),
        ));

        self::assertSame('partial', $result->status);
        self::assertSame(0, $result->archived);
        self::assertSame(2, $this->databaseInteger(
            "SELECT COUNT(*) FROM pve_nodes WHERE connection_id = :connection_id AND inventory_state = 'active'",
        ));
        self::assertSame(2, $this->databaseInteger(
            "SELECT COUNT(*) FROM guests WHERE connection_id = :connection_id AND inventory_state = 'active'",
        ));
        self::assertSame(2, $this->databaseInteger(
            'SELECT COUNT(*) FROM guest_placements WHERE connection_id = :connection_id',
        ));
        self::assertSame(
            [
                ['scope_type' => 'pve_guests', 'status' => 'partial'],
                ['scope_type' => 'pve_node_storages', 'status' => 'complete'],
                ['scope_type' => 'pve_storages', 'status' => 'complete'],
                ['scope_type' => 'pve_topology', 'status' => 'complete'],
            ],
            $this->connection()->fetchAllAssociative(
                <<<'SQL'
                    SELECT scope_type, status
                    FROM inventory_sync_scope_results
                    WHERE sync_run_id = :run_id
                    ORDER BY scope_type
                    SQL,
                ['run_id' => $partialRun->binary()],
            ),
        );
    }

    public function testFirstPartialRunIsDiagnosticOnlyAndCreatesNoBindingOrCoreRows(): void
    {
        [$lease, $run] = $this->startSelectedRun('first-partial');
        $result = $this->store->apply($lease, $this->commit(
            $run,
            ['node-a'],
            [],
            topology: InventoryScopeStatus::Partial,
            guests: InventoryScopeStatus::Failed,
            observedAt: self::at(1),
        ));

        self::assertTrue($result->diagnosticOnly);
        self::assertSame('partial', $result->status);
        self::assertSame(0, $result->created);
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM proxmox_installation_bindings'));
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_clusters'));
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_nodes'));
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM guests'));
        self::assertSame(4, $this->databaseInteger(
            'SELECT COUNT(*) FROM inventory_sync_scope_results WHERE sync_run_id = :run_id',
            ['run_id' => $run->binary()],
        ));
        self::assertSame(
            ['status' => 'partial', 'authoritative' => '0', 'objects_created' => '0'],
            $this->connection()->fetchAssociative(
                <<<'SQL'
                    SELECT status, CAST(authoritative AS CHAR) AS authoritative,
                           CAST(objects_created AS CHAR) AS objects_created
                    FROM inventory_sync_runs WHERE id = :run_id
                    SQL,
                ['run_id' => $run->binary()],
            ),
        );
    }

    public function testFinishWithoutSnapshotAtomicallyTerminatesRunWithTypedCodeOnly(): void
    {
        $lease = $this->seedOwnedLease('terminal-no-snapshot');
        $run = self::id('run-terminal-no-snapshot');
        $this->store->beginRun($lease, new PveSyncRunStart($run, $this->connectionId, 1, self::at(0)));
        $failure = new PveSyncRunFailure(
            $run,
            $this->connectionId,
            1,
            ConnectionReadFailureCode::EndpointsExhausted,
            self::at(2),
        );

        $this->store->finishWithoutSnapshot($lease, $failure);

        self::assertSame(
            [
                'status' => 'failed',
                'authoritative' => '0',
                'finished_at' => '2026-07-11 00:00:02.000000',
                'applied_at' => null,
                'error_code' => 'endpoints_exhausted',
                'error_summary' => null,
            ],
            $this->connection()->fetchAssociative(
                <<<'SQL'
                    SELECT status, CAST(authoritative AS CHAR) AS authoritative, finished_at, applied_at,
                           error_code, error_summary
                    FROM inventory_sync_runs WHERE id = :run_id
                    SQL,
                ['run_id' => $run->binary()],
            ),
        );
        self::assertSame(0, $this->databaseInteger(
            'SELECT COUNT(*) FROM inventory_sync_scope_results WHERE sync_run_id = :run_id',
            ['run_id' => $run->binary()],
        ));
        self::assertSame(0, $this->databaseInteger(
            'SELECT COUNT(*) FROM inventory_sync_failures WHERE sync_run_id = :run_id',
            ['run_id' => $run->binary()],
        ));
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_clusters'));

        try {
            $this->store->finishWithoutSnapshot($lease, $failure);
            self::fail('A terminal run must not be finished twice.');
        } catch (CollectorLeaseOwnershipLost|PveCoreInventoryConflict) {
            self::addToAssertionCount(1);
        }
    }

    public function testFinishWithoutSnapshotClosesRevisionDriftButFailsClosedOnFenceDrift(): void
    {
        $lease = $this->seedOwnedLease('terminal-drift');
        $run = self::id('run-terminal-drift');
        $this->store->beginRun($lease, new PveSyncRunStart($run, $this->connectionId, 1, self::at(0)));
        $failure = new PveSyncRunFailure(
            $run,
            $this->connectionId,
            1,
            ConnectionReadFailureCode::TerminalEndpointFailure,
            self::at(2),
        );

        $this->connection()->update('proxmox_connections', ['revision' => 2], [
            'id' => $this->connectionId->binary(),
        ]);
        $this->store->finishWithoutSnapshot($lease, $failure);
        self::assertSame(
            ['status' => 'failed', 'error_code' => 'connection_changed'],
            $this->connection()->fetchAssociative(
                'SELECT status, error_code FROM inventory_sync_runs WHERE id = :run_id',
                ['run_id' => $run->binary()],
            ),
        );

        $fencedRun = self::id('run-terminal-fence-drift');
        $fencedLease = $this->seedOwnedLease('terminal-fence-drift');
        $this->connection()->update('proxmox_connections', ['revision' => 1], [
            'id' => $this->connectionId->binary(),
        ]);
        $this->store->beginRun($fencedLease, new PveSyncRunStart($fencedRun, $this->connectionId, 1, self::at(0)));
        $fencedFailure = new PveSyncRunFailure(
            $fencedRun,
            $this->connectionId,
            1,
            ConnectionReadFailureCode::TerminalEndpointFailure,
            self::at(2),
        );

        $this->connection()->executeStatement(
            "UPDATE collector_schedule SET lease_fencing_token = lease_fencing_token + 1 WHERE schedule_name = 'inventory'",
        );
        try {
            $this->store->finishWithoutSnapshot($fencedLease, $fencedFailure);
            self::fail('A stale fence must reject terminal run completion.');
        } catch (CollectorLeaseOwnershipLost) {
            self::addToAssertionCount(1);
        }
        self::assertSame('running', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run_id',
            ['run_id' => $fencedRun->binary()],
        ));

        $this->restoreScheduleFence($fencedLease);
        $this->store->finishWithoutSnapshot($fencedLease, $fencedFailure);
        self::assertSame('failed', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run_id',
            ['run_id' => $fencedRun->binary()],
        ));
    }

    public function testFinishWithoutSnapshotRejectsFailureRevisionThatDiffersFromOwnedRun(): void
    {
        $lease = $this->seedOwnedLease('terminal-failure-revision');
        $run = self::id('run-terminal-failure-revision');
        $this->store->beginRun($lease, new PveSyncRunStart($run, $this->connectionId, 1, self::at(0)));
        $failure = new PveSyncRunFailure(
            $run,
            $this->connectionId,
            2,
            ConnectionReadFailureCode::TerminalEndpointFailure,
            self::at(2),
        );

        try {
            $this->store->finishWithoutSnapshot($lease, $failure);
            self::fail('A failure carrying a different run revision was accepted.');
        } catch (PveCoreInventoryConflict) {
            self::addToAssertionCount(1);
        }
        self::assertSame('running', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run_id',
            ['run_id' => $run->binary()],
        ));
    }

    public function testAuthoritativeDiffArchivesPlacementBeforeNodeAndReactivatesStableIds(): void
    {
        [$seedLease, $seedRun] = $this->startSelectedRun('archive-seed');
        $this->store->apply($seedLease, $this->commit(
            $seedRun,
            ['node-a', 'node-b'],
            [
                $this->guest(PveGuestType::Qemu, 100, 'node-a', 'active'),
                $this->guest(PveGuestType::Lxc, 200, 'node-b', 'will-return'),
            ],
            observedAt: self::at(1),
        ));
        $nodeBefore = $this->rowByNaturalKey('pve_nodes', 'connection_id = :connection_id AND node_name = :name', [
            'connection_id' => $this->connectionId->binary(), 'name' => 'node-b',
        ]);
        $guestBefore = $this->rowByNaturalKey(
            'guests',
            "connection_id = :connection_id AND guest_type = 'lxc' AND vmid = 200",
            ['connection_id' => $this->connectionId->binary()],
        );

        [$archiveLease, $archiveRun] = $this->startSelectedRun('archive');
        $archiveResult = $this->store->apply($archiveLease, $this->commit(
            $archiveRun,
            ['node-a'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'active')],
            observedAt: self::at(2),
        ));

        self::assertSame(2, $archiveResult->archived);
        self::assertSame(1, $this->databaseInteger(
            'SELECT COUNT(*) FROM guest_placements WHERE guest_id = :guest_id',
            ['guest_id' => $guestBefore['id']],
        ));
        $archivedNode = $this->rowById('pve_nodes', $nodeBefore['id']);
        $archivedGuest = $this->rowById('guests', $guestBefore['id']);
        self::assertSame('archived', $archivedNode['inventory_state']);
        self::assertSame('archived', $archivedGuest['inventory_state']);
        self::assertSame('2026-07-11 00:00:02.000000', $archivedNode['archived_at']);
        self::assertSame('2026-07-11 00:00:02.000000', $archivedGuest['archived_at']);

        [$partialLease, $partialRun] = $this->startSelectedRun('archive-partial');
        $this->store->apply($partialLease, $this->commit(
            $partialRun,
            ['node-a'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'active')],
            topology: InventoryScopeStatus::Complete,
            guests: InventoryScopeStatus::Partial,
            observedAt: self::at(3),
        ));
        self::assertSame($archivedNode['archived_at'], $this->rowById('pve_nodes', $nodeBefore['id'])['archived_at']);
        self::assertSame($archivedGuest['archived_at'], $this->rowById('guests', $guestBefore['id'])['archived_at']);

        [$returnLease, $returnRun] = $this->startSelectedRun('reactivate');
        $reactivation = $this->store->apply($returnLease, $this->commit(
            $returnRun,
            ['node-a', 'node-b'],
            [
                $this->guest(PveGuestType::Qemu, 100, 'node-a', 'active'),
                $this->guest(PveGuestType::Lxc, 200, 'node-b', 'returned'),
            ],
            observedAt: self::at(4),
        ));

        self::assertSame(0, $reactivation->created);
        $nodeAfter = $this->rowById('pve_nodes', $nodeBefore['id']);
        $guestAfter = $this->rowById('guests', $guestBefore['id']);
        self::assertSame('active', $nodeAfter['inventory_state']);
        self::assertSame('active', $guestAfter['inventory_state']);
        self::assertNull($nodeAfter['archived_at']);
        self::assertNull($guestAfter['archived_at']);
        self::assertSame($nodeBefore['first_seen_at'], $nodeAfter['first_seen_at']);
        self::assertSame($guestBefore['first_seen_at'], $guestAfter['first_seen_at']);
        self::assertSame(1, $this->databaseInteger(
            'SELECT COUNT(*) FROM guest_placements WHERE guest_id = :guest_id',
            ['guest_id' => $guestBefore['id']],
        ));
        self::assertSame(1, $this->databaseInteger(
            'SELECT placement_revision FROM guest_placements WHERE guest_id = :guest_id',
            ['guest_id' => $guestBefore['id']],
        ));
    }

    public function testStaleFenceAndRevisionDriftFailBeforeAnyCoreMutation(): void
    {
        [$staleLease, $staleRun] = $this->startSelectedRun('stale-fence');
        $this->connection()->executeStatement(
            "UPDATE collector_schedule SET lease_fencing_token = lease_fencing_token + 1 WHERE schedule_name = 'inventory'",
        );

        try {
            $this->store->apply($staleLease, $this->commit(
                $staleRun,
                ['node-a'],
                [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'blocked')],
                observedAt: self::at(1),
            ));
            self::fail('A stale collector fence must reject the inventory write.');
        } catch (CollectorLeaseOwnershipLost) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_clusters'));

        $this->restoreScheduleFence($staleLease);
        [$revisionLease, $revisionRun] = $this->startSelectedRun('revision-drift');
        $this->connection()->update('proxmox_connections', ['revision' => 2], [
            'id' => $this->connectionId->binary(),
        ]);
        try {
            $this->store->apply($revisionLease, $this->commit(
                $revisionRun,
                ['node-a'],
                [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'blocked')],
                observedAt: self::at(2),
            ));
            self::fail('A connection revision drift must reject the inventory write.');
        } catch (PveCoreInventoryConflict) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_clusters'));
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM proxmox_installation_bindings'));
    }

    public function testSameNamedClusterWithDisjointMembershipIsRejectedAndRunCanApplyOnlyOnce(): void
    {
        [$seedLease, $seedRun] = $this->startSelectedRun('membership-seed');
        $seedCommit = $this->commit(
            $seedRun,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'stable')],
            observedAt: self::at(1),
        );
        $this->store->apply($seedLease, $seedCommit);
        $before = $this->rowsByNaturalKey(
            'SELECT id, node_name, inventory_state, last_seen_at FROM pve_nodes WHERE connection_id = :connection_id ORDER BY node_name',
        );

        try {
            $this->store->apply($seedLease, $seedCommit);
            self::fail('A finalized inventory sync run must not apply twice.');
        } catch (CollectorLeaseOwnershipLost|PveCoreInventoryConflict) {
            self::addToAssertionCount(1);
        }
        self::assertSame($before, $this->rowsByNaturalKey(
            'SELECT id, node_name, inventory_state, last_seen_at FROM pve_nodes WHERE connection_id = :connection_id ORDER BY node_name',
        ));

        [$disjointLease, $disjointRun] = $this->startSelectedRun('membership-disjoint');
        try {
            $this->store->apply($disjointLease, $this->commit(
                $disjointRun,
                ['node-x', 'node-y'],
                [$this->guest(PveGuestType::Qemu, 200, 'node-x', 'foreign')],
                observedAt: self::at(2),
            ));
            self::fail('A same-named cluster with disjoint membership must not be accepted.');
        } catch (PveCoreInventoryConflict) {
            self::addToAssertionCount(1);
        }
        self::assertSame($before, $this->rowsByNaturalKey(
            'SELECT id, node_name, inventory_state, last_seen_at FROM pve_nodes WHERE connection_id = :connection_id ORDER BY node_name',
        ));
        self::assertSame('running', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run_id',
            ['run_id' => $disjointRun->binary()],
        ));
    }

    public function testMidWriteMariaDbFailureRollsBackBindingAndAllCoreChanges(): void
    {
        [$lease, $run] = $this->startSelectedRun('rollback');
        $triggerName = 'hoddmimir_test_pve_core_rollback';
        $this->connection()->executeStatement('DROP TRIGGER IF EXISTS '.$triggerName);
        $this->connection()->executeStatement(<<<SQL
            CREATE TRIGGER {$triggerName}
            BEFORE INSERT ON guests
            FOR EACH ROW
            BEGIN
                IF NEW.name = '__test_mid_write_failure__' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'intentional integration rollback';
                END IF;
            END
            SQL);

        try {
            try {
                $this->store->apply($lease, $this->commit(
                    $run,
                    ['node-a'],
                    [$this->guest(PveGuestType::Qemu, 100, 'node-a', '__test_mid_write_failure__')],
                    observedAt: self::at(1),
                ));
                self::fail('The test trigger must interrupt the aggregate write.');
            } catch (Throwable $exception) {
                self::assertStringContainsString('intentional integration rollback', $exception->getMessage());
            }

            foreach (['proxmox_installation_bindings', 'pve_clusters', 'pve_nodes', 'guests', 'guest_placements', 'inventory_sync_scope_results'] as $table) {
                self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM '.$table), $table);
            }
            self::assertSame(
                ['status' => 'running', 'applied_at' => null],
                $this->connection()->fetchAssociative(
                    'SELECT status, applied_at FROM inventory_sync_runs WHERE id = :run_id',
                    ['run_id' => $run->binary()],
                ),
            );
            self::assertSame(1, $this->databaseInteger(
                'SELECT COUNT(*) FROM inventory_sync_endpoint_attempts WHERE sync_run_id = :run_id',
                ['run_id' => $run->binary()],
            ));
        } finally {
            $this->connection()->executeStatement('DROP TRIGGER IF EXISTS '.$triggerName);
        }
    }

    public function testEndpointAttemptsPersistOnlyStructuredOutcomeAndCode(): void
    {
        $lease = $this->seedOwnedLease('attempts');
        $run = self::id('run-attempts');
        $this->store->beginRun($lease, new PveSyncRunStart($run, $this->connectionId, 1, self::at(0)));
        $this->store->recordEndpointAttempt($lease, new PveEndpointAttempt(
            self::id('attempt-failover'),
            $run,
            $this->connectionId,
            $this->primaryEndpointId,
            1,
            EndpointAttemptOutcome::Failover,
            'transport_error',
            self::at(1),
            self::at(2),
        ));
        $this->store->recordEndpointAttempt($lease, new PveEndpointAttempt(
            self::id('attempt-selected'),
            $run,
            $this->connectionId,
            $this->secondaryEndpointId,
            2,
            EndpointAttemptOutcome::Selected,
            null,
            self::at(3),
            self::at(4),
        ));

        self::assertSame(
            [
                ['attempt_number' => '1', 'outcome' => 'failover', 'error_code' => 'transport_error'],
                ['attempt_number' => '2', 'outcome' => 'selected', 'error_code' => null],
            ],
            $this->connection()->fetchAllAssociative(
                <<<'SQL'
                    SELECT CAST(attempt_number AS CHAR) AS attempt_number, outcome, error_code
                    FROM inventory_sync_endpoint_attempts
                    WHERE sync_run_id = :run_id
                    ORDER BY attempt_number
                    SQL,
                ['run_id' => $run->binary()],
            ),
        );
        $columns = $this->connection()->fetchFirstColumn(
            <<<'SQL'
                SELECT COLUMN_NAME
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_sync_endpoint_attempts'
                ORDER BY ORDINAL_POSITION
                SQL,
        );
        self::assertSame(
            [
                'id', 'connection_id', 'sync_run_id', 'endpoint_id', 'attempt_number', 'outcome', 'error_code',
                'selected_sync_run_id', 'started_at', 'finished_at',
            ],
            $columns,
        );
        self::assertSame($this->secondaryEndpointId->binary(), $this->binaryColumn(
            'SELECT endpoint_id FROM inventory_sync_runs WHERE id = :run_id',
            ['run_id' => $run->binary()],
        ));

        $thirdEndpoint = self::id('endpoint-third');
        $this->insertEndpoint($this->connectionId, $thirdEndpoint, 'pve-c.test');
        try {
            $this->store->recordEndpointAttempt($lease, new PveEndpointAttempt(
                self::id('attempt-second-selected'),
                $run,
                $this->connectionId,
                $thirdEndpoint,
                3,
                EndpointAttemptOutcome::Selected,
                null,
                self::at(5),
                self::at(5),
            ));
            self::fail('A sync run must bind its selected endpoint exactly once.');
        } catch (PveCoreInventoryConflict) {
            self::addToAssertionCount(1);
        }

        try {
            $this->connection()->insert('inventory_sync_endpoint_attempts', [
                'id' => self::id('attempt-direct-second-selected')->binary(),
                'connection_id' => $this->connectionId->binary(),
                'sync_run_id' => $run->binary(),
                'endpoint_id' => $thirdEndpoint->binary(),
                'attempt_number' => 3,
                'outcome' => 'selected',
                'error_code' => null,
                'started_at' => $this->format(self::at(5)),
                'finished_at' => $this->format(self::at(5)),
            ]);
            self::fail('MariaDB must enforce at most one selected attempt per sync run.');
        } catch (UniqueConstraintViolationException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(2, $this->databaseInteger(
            'SELECT COUNT(*) FROM inventory_sync_endpoint_attempts WHERE sync_run_id = :run_id',
            ['run_id' => $run->binary()],
        ));
    }

    public function testApplyRequiresTheEndpointBoundOnTheRunAsWellAsTheSelectedAttempt(): void
    {
        [$lease, $run] = $this->startSelectedRun('run-endpoint-drift');
        $this->connection()->update('inventory_sync_runs', [
            'endpoint_id' => $this->secondaryEndpointId->binary(),
        ], ['id' => $run->binary()]);

        try {
            $this->store->apply($lease, $this->commit(
                $run,
                ['node-a'],
                [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'blocked')],
            ));
            self::fail('Apply must match the endpoint bound exactly once on the sync run.');
        } catch (PveCoreInventoryConflict) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_clusters'));
        self::assertSame('running', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run_id',
            ['run_id' => $run->binary()],
        ));
    }

    public function testTwoDatabaseConnectionsRacingTheSameRunCommitAtMostOnce(): void
    {
        [$lease, $run] = $this->startSelectedRun('same-run-race');
        $commit = $this->commit(
            $run,
            ['node-a'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'race-winner')],
        );

        $results = $this->runApplyChildren($lease, $commit, 2);
        sort($results);

        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => 'ok' === $result)));
        self::assertCount(1, array_filter(
            $results,
            static fn (string $result): bool => str_contains($result, 'CollectorLeaseOwnershipLost')
                || str_contains($result, 'PveCoreInventoryConflict'),
        ));
        self::assertSame(1, $this->databaseInteger('SELECT COUNT(*) FROM pve_clusters'));
        self::assertSame(1, $this->databaseInteger('SELECT COUNT(*) FROM guests'));
        self::assertSame('succeeded', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run_id',
            ['run_id' => $run->binary()],
        ));
    }

    public function testRacingPlacementMoveIncrementsRevisionExactlyOnce(): void
    {
        [$seedLease, $seedRun] = $this->startSelectedRun('placement-race-seed');
        $this->store->apply($seedLease, $this->commit(
            $seedRun,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'guest')],
        ));

        [$moveLease, $moveRun] = $this->startSelectedRun('placement-race-move');
        $move = $this->commit(
            $moveRun,
            ['node-a', 'node-b'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-b', 'guest')],
            observedAt: self::at(2),
        );

        $results = $this->runApplyChildren($moveLease, $move, 2);
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => 'ok' === $result)));
        self::assertCount(1, array_filter(
            $results,
            static fn (string $result): bool => str_contains($result, 'CollectorLeaseOwnershipLost')
                || str_contains($result, 'PveCoreInventoryConflict'),
        ));
        self::assertSame(2, $this->databaseInteger(
            <<<'SQL'
                SELECT placement.placement_revision
                FROM guest_placements AS placement
                JOIN guests AS guest ON guest.id = placement.guest_id
                WHERE guest.connection_id = :connection_id
                  AND guest.guest_type = 'qemu'
                  AND guest.vmid = 100
                SQL,
        ));
    }

    public function testLeaseExpiryDuringScheduleLockWaitRollsBackAndSkipsTheAbandonedTick(): void
    {
        [$lease, $run] = $this->startSelectedRun('expiry-lock-wait');
        $commit = $this->commit(
            $run,
            ['node-a'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'expired')],
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
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_clusters'));
        self::assertSame('running', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run_id',
            ['run_id' => $run->binary()],
        ));

        $takeoverConnection = DriverManager::getConnection($this->connection()->getParams());
        try {
            $takeoverWorker = new CollectorWorkerId(self::bytes('worker-takeover'));
            $takeoverToken = new CollectorCycleToken(self::bytes('cycle-takeover'));
            $this->recordCollectorHeartbeat($takeoverConnection, $takeoverWorker);
            $decision = (new DbalCollectorScheduleStore($takeoverConnection))->claimDue(
                $takeoverWorker,
                $takeoverToken,
                3600,
            );
            self::assertFalse($decision->isClaimed());
            self::assertNull($decision->lease);
            self::assertGreaterThan($decision->databaseNow, $decision->retryAt);
            self::assertSame('abandoned', $takeoverConnection->fetchOne(
                'SELECT status FROM collector_cycles WHERE cycle_token = :token',
                ['token' => $lease->token->binary()],
            ));
            self::assertSame(
                ['status' => 'abandoned', 'error_code' => 'collector_lease_expired'],
                $takeoverConnection->fetchAssociative(
                    'SELECT status, error_code FROM inventory_sync_runs WHERE id = :run_id',
                    ['run_id' => $run->binary()],
                ),
            );

            try {
                $this->store->apply($lease, $commit);
                self::fail('The writer from the abandoned cycle must lose ownership after takeover.');
            } catch (CollectorLeaseOwnershipLost) {
                self::addToAssertionCount(1);
            }
            self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_clusters'));
            self::assertSame(1, $this->databaseInteger('SELECT COUNT(*) FROM collector_cycles'));
        } finally {
            $takeoverConnection->close();
        }
    }

    public function testRevisionDriftDuringConnectionLockWaitRejectsAndRollsBack(): void
    {
        [$lease, $run] = $this->startSelectedRun('revision-lock-wait');
        $commit = $this->commit(
            $run,
            ['node-a'],
            [$this->guest(PveGuestType::Qemu, 100, 'node-a', 'revision-drift')],
        );

        $this->connection()->beginTransaction();
        $this->connection()->fetchAssociative(
            'SELECT * FROM proxmox_connections WHERE id = :id FOR UPDATE',
            ['id' => $this->connectionId->binary()],
        );
        [$processId, $socket] = $this->startApplyChild($lease, $commit);
        $this->releaseApplyChild($socket);
        usleep(150_000);
        $this->connection()->update('proxmox_connections', ['revision' => 2], [
            'id' => $this->connectionId->binary(),
        ]);
        $this->connection()->commit();

        $result = $this->finishApplyChild($processId, $socket);
        self::assertStringContainsString('PveCoreInventoryConflict', $result);
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM pve_clusters'));
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM inventory_sync_scope_results'));
        self::assertSame('running', $this->connection()->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE id = :run_id',
            ['run_id' => $run->binary()],
        ));
    }

    /** @return array{CollectorLease, InventoryIdentifier} */
    private function startSelectedRun(string $label): array
    {
        $lease = $this->seedOwnedLease($label);
        $run = self::id('run-'.$label);
        $this->store->beginRun($lease, new PveSyncRunStart($run, $this->connectionId, 1, self::at(0)));
        $this->store->recordEndpointAttempt($lease, new PveEndpointAttempt(
            self::id('attempt-'.$label),
            $run,
            $this->connectionId,
            $this->primaryEndpointId,
            1,
            EndpointAttemptOutcome::Selected,
            null,
            self::at(0),
            self::at(0),
        ));

        return [$lease, $run];
    }

    /**
     * @param list<string>              $nodeNames
     * @param list<PveGuestObservation> $guestObservations
     * @param list<PveStorageObservation> $storages
     * @param list<PveNodeStorageStateObservation> $storageStates
     */
    private function commit(
        InventoryIdentifier $run,
        array $nodeNames,
        array $guestObservations,
        InventoryScopeStatus $topology = InventoryScopeStatus::Complete,
        InventoryScopeStatus $guests = InventoryScopeStatus::Complete,
        ?DateTimeImmutable $observedAt = null,
        array $storages = [],
        array $storageStates = [],
        InventoryScopeStatus $storageScope = InventoryScopeStatus::Complete,
        InventoryScopeStatus $nodeStorageScope = InventoryScopeStatus::Complete,
    ): PveInventoryCommit {
        $core = new PveCoreInventoryCommit(
            $run,
            $this->connectionId,
            $this->primaryEndpointId,
            1,
            new PveCoreInstallationBinding(PveCoreBindingKind::Cluster, 'cluster-alpha'),
            new PveCoreScopeResult(PveCoreScope::Topology, $topology),
            new PveCoreScopeResult(PveCoreScope::Guests, $guests),
            array_map(static fn (string $name): PveNodeObservation => new PveNodeObservation($name, 'online'), $nodeNames),
            $guestObservations,
            $observedAt ?? self::at(1),
        );
        return new PveInventoryCommit(
            $core,
            new PveCoreScopeResult(PveCoreScope::Storages, $storageScope),
            array_map(
                static fn (string $name): PveNodeStorageScopeResult => new PveNodeStorageScopeResult($name, $nodeStorageScope),
                $nodeNames,
            ),
            $storages,
            $storageStates,
        );
    }

    private function storage(
        string $name,
        bool $disabled,
        ?PvePbsStorageMapping $mapping,
        DateTimeImmutable $observedAt,
    ): PveStorageObservation {
        return new PveStorageObservation(
            $name,
            null === $mapping ? 'dir' : 'pbs',
            ['backup'],
            null,
            $disabled,
            false,
            $mapping,
            $observedAt,
        );
    }

    private function storageState(
        string $node,
        string $storage,
        PveStorageCapacityStatus $status,
        DateTimeImmutable $observedAt,
        ?int $total = null,
        ?int $used = null,
        ?int $available = null,
    ): PveNodeStorageStateObservation {
        return new PveNodeStorageStateObservation(
            $node,
            $storage,
            true,
            true,
            false,
            $status,
            $total,
            $used,
            $available,
            $observedAt,
        );
    }

    private function guest(
        PveGuestType $type,
        int $vmid,
        string $node,
        string $name,
        ?int $diskWriteBytes = null,
    ): PveGuestObservation
    {
        return new PveGuestObservation($type, $vmid, $node, $name, false, $diskWriteBytes);
    }

    /** Directly seeds an owned lease for writer-only tests; takeover tests must call the production scheduler store. */
    private function seedOwnedLease(string $label): CollectorLease
    {
        ++$this->nextFence;
        $now = $this->databaseNow();
        $expires = $now->modify('+1 hour');
        $worker = new CollectorWorkerId(self::bytes('worker'));
        $token = new CollectorCycleToken(self::bytes('cycle-'.$label));

        $this->connection()->executeStatement(
            <<<'SQL'
                UPDATE collector_cycles
                SET status = 'succeeded', heartbeat_at = :now, finished_at = :now, duration_ms = 0
                WHERE status = 'running'
                SQL,
            ['now' => $this->format($now)],
        );
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO worker_heartbeats (
                    worker_instance_id, worker_kind, status, started_at, heartbeat_at, expires_at,
                    current_activity, current_cycle_token, next_action_at, build_version
                ) VALUES (
                    :worker, 'collector', 'ready', :now, :now, :expires, NULL, NULL, NULL, 'integration-test'
                ) ON DUPLICATE KEY UPDATE heartbeat_at = VALUES(heartbeat_at), expires_at = VALUES(expires_at)
                SQL,
            ['worker' => $worker->bytes, 'now' => $this->format($now), 'expires' => $this->format($expires)],
        );
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO collector_schedule (
                    schedule_name, grid_started_at, interval_seconds, next_scan_at, lease_owner, lease_token,
                    lease_fencing_token, lease_acquired_at, lease_expires_at, last_cycle_started_at, updated_at
                ) VALUES (
                    'inventory', :now, 120, :now, :worker, :token, :fence, :now, :expires, :now, :now
                ) ON DUPLICATE KEY UPDATE
                    lease_owner = VALUES(lease_owner), lease_token = VALUES(lease_token),
                    lease_fencing_token = VALUES(lease_fencing_token),
                    lease_acquired_at = VALUES(lease_acquired_at), lease_expires_at = VALUES(lease_expires_at),
                    last_cycle_started_at = VALUES(last_cycle_started_at),
                    updated_at = VALUES(updated_at)
                SQL,
            [
                'now' => $this->format($now), 'worker' => $worker->bytes, 'token' => $token->binary(),
                'fence' => $this->nextFence, 'expires' => $this->format($expires),
            ],
        );
        $this->connection()->insert('collector_cycles', [
            'cycle_token' => $token->binary(),
            'schedule_name' => 'inventory',
            'worker_instance_id' => $worker->bytes,
            'worker_kind' => 'collector',
            'fencing_token' => $this->nextFence,
            'scheduled_for' => $this->format($now),
            'started_at' => $this->format($now),
            'heartbeat_at' => $this->format($now),
            'status' => 'running',
        ]);

        return new CollectorLease($worker, $token, $this->nextFence, $expires);
    }

    private function recordCollectorHeartbeat(Connection $connection, CollectorWorkerId $worker): void
    {
        $now = $this->databaseNow();
        $connection->insert('worker_heartbeats', [
            'worker_instance_id' => $worker->bytes,
            'worker_kind' => 'collector',
            'status' => 'ready',
            'started_at' => $this->format($now),
            'heartbeat_at' => $this->format($now),
            'expires_at' => $this->format($now->modify('+1 hour')),
            'current_activity' => null,
            'current_cycle_token' => null,
            'next_action_at' => null,
            'build_version' => 'integration-test',
        ]);
    }

    private function restoreScheduleFence(CollectorLease $lease): void
    {
        $this->connection()->update('collector_schedule', [
            'lease_fencing_token' => $lease->fencingToken,
        ], ['schedule_name' => 'inventory']);
    }

    private function insertConnection(
        InventoryIdentifier $connection,
        InventoryIdentifier $primaryEndpoint,
        InventoryIdentifier $secondaryEndpoint,
    ): void {
        $now = self::at(0)->format('Y-m-d H:i:s.u');
        $this->connection()->insert('proxmox_connections', [
            'id' => $connection->binary(),
            'display_name' => 'PVE integration '.bin2hex($connection->binary()),
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([[$primaryEndpoint, 'pve-a.test'], [$secondaryEndpoint, 'pve-b.test']] as [$endpoint, $host]) {
            $this->insertEndpoint($connection, $endpoint, $host, $now);
        }
    }

    private function insertEndpoint(
        InventoryIdentifier $connection,
        InventoryIdentifier $endpoint,
        string $host,
        ?string $now = null,
    ): void {
        $timestamp = $now ?? self::at(0)->format('Y-m-d H:i:s.u');
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $endpoint->binary(),
            'connection_id' => $connection->binary(),
            'host' => $host,
            'port' => 8006,
            'priority' => 100,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /** @return list<string> */
    private function runApplyChildren(
        CollectorLease $lease,
        PveInventoryCommit $commit,
        int $count,
    ): array {
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
    private function startApplyChild(CollectorLease $lease, PveInventoryCommit $commit): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $sockets) {
            throw new \RuntimeException('Could not create the integration-test process socket.');
        }
        [$parentSocket, $childSocket] = $sockets;
        $parameters = $this->connection()->getParams();
        $processId = pcntl_fork();
        if (-1 === $processId) {
            throw new \RuntimeException('Could not fork the integration-test apply process.');
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
                $childStore = new DbalPveCoreInventoryStore(
                    $childConnection,
                    new SequentialInventoryIdentifierGenerator(),
                );
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
            throw new \RuntimeException('Could not release the integration-test apply process.');
        }
    }

    /** @param resource $socket */
    private function finishApplyChild(int $processId, $socket): string
    {
        $result = stream_get_contents($socket);
        fclose($socket);
        $status = 0;
        if ($processId !== pcntl_waitpid($processId, $status)) {
            throw new \RuntimeException('Could not wait for the integration-test apply process.');
        }
        if (!is_int($status)) {
            throw new \RuntimeException('The integration-test apply process returned an invalid wait status.');
        }
        if (!pcntl_wifexited($status) || 0 !== pcntl_wexitstatus($status)) {
            throw new \RuntimeException('The integration-test apply process failed unexpectedly.');
        }
        if (false === $result || '' === $result) {
            throw new \RuntimeException('The integration-test apply process returned no result.');
        }

        return $result;
    }

    /** @param array<string, mixed> $parameters */
    private function databaseInteger(string $sql, array $parameters = []): int
    {
        $value = $this->connection()->fetchOne($sql, $parameters ?: ['connection_id' => $this->connectionId->binary()]);
        self::assertTrue(is_int($value) || (is_string($value) && ctype_digit($value)));

        return (int) $value;
    }

    private static function assertDatabaseNumericValue(int $expected, mixed $value): void
    {
        self::assertTrue(is_int($value) || (is_string($value) && ctype_digit($value)));
        self::assertSame($expected, (int) $value);
    }

    /** @param array<string, mixed> $parameters */
    private function binaryColumn(string $sql, array $parameters = []): string
    {
        $value = $this->connection()->fetchOne($sql, $parameters ?: ['connection_id' => $this->connectionId->binary()]);
        self::assertIsString($value);
        self::assertSame(16, strlen($value));

        return $value;
    }

    /** @param array<string, mixed> $parameters
     *  @return array<string, mixed>
     */
    private function rowByNaturalKey(string $table, string $where, array $parameters): array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM '.$table.' WHERE '.$where, $parameters);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function rowById(string $table, mixed $id): array
    {
        self::assertIsString($id);

        return $this->rowByNaturalKey($table, 'id = :id', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    private function rowsByNaturalKey(string $sql): array
    {
        return $this->connection()->fetchAllAssociative($sql, ['connection_id' => $this->connectionId->binary()]);
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
        return (new DateTimeImmutable(self::NOW))->modify(sprintf('+%d seconds', $seconds));
    }

    private static function id(string $label): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes('id-'.$label));
    }

    private static function bytes(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }

    private function connection(): Connection
    {
        return $this->database;
    }

    private function cleanDatabase(): void
    {
        foreach ([
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

final class SequentialInventoryIdentifierGenerator implements InventoryIdentifierGenerator
{
    private int $sequence = 0;

    public function generate(): InventoryIdentifier
    {
        ++$this->sequence;

        return new InventoryIdentifier(substr(hash('sha256', 'generated-'.$this->sequence, true), 0, 16));
    }
}
