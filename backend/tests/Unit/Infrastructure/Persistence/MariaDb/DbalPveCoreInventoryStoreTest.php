<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

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
use App\Application\Inventory\Pve\PveEndpointAttempt;
use App\Application\Inventory\Pve\PveGuestObservation;
use App\Application\Inventory\Pve\PveInventoryCommit;
use App\Application\Inventory\Pve\PveNodeObservation;
use App\Application\Inventory\Pve\PveNodeStorageScopeResult;
use App\Application\Inventory\Pve\PveNodeStorageStateObservation;
use App\Application\Inventory\Pve\PveStorageCapacityStatus;
use App\Application\Inventory\Pve\PveStorageObservation;
use App\Application\Inventory\Pve\PveSyncRunFailure;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PvePbsStorageMapping;
use App\Infrastructure\Persistence\MariaDb\DbalPveCoreInventoryStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalPveCoreInventoryStoreTest extends TestCase
{
    private const string NOW = '2026-07-11 12:00:00.000000';

    public function testLifecycleWritesNewCoreAndStorageInventoryThroughFencedTransactions(): void
    {
        $begin = new PveStoreRecording();
        $this->store($this->database(recording: $begin))->beginRun(
            $this->lease(),
            new PveSyncRunStart($this->id('r'), $this->id('c'), 1, $this->now()),
        );
        self::assertSame(['inventory_sync_runs'], array_column($begin->inserts, 0));

        $attempt = new PveStoreRecording();
        $this->store($this->database(['run_endpoint' => null], $attempt))->recordEndpointAttempt(
            $this->lease(),
            $this->attempt(),
        );
        self::assertSame('selected', $attempt->inserts[0][1]['outcome']);
        self::assertArrayHasKey('endpoint_id', $attempt->updates[0][1]);

        $apply = new PveStoreRecording();
        $result = $this->store($this->database(recording: $apply))->apply(
            $this->lease(),
            $this->commit(authoritative: true, withStorage: true),
        );

        self::assertSame('succeeded', $result->status);
        self::assertSame(5, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->archived);
        self::assertFalse($result->diagnosticOnly);
        self::assertContains('pve_storages', array_column($apply->inserts, 0));
        self::assertStringContainsString('pve_storage_pbs_mappings', implode('\n', array_column($apply->statements, 0)));
        self::assertStringContainsString('pve_node_storage_state', implode('\n', array_column($apply->statements, 0)));
    }

    public function testFailoverAttemptOnlyUpdatesHeartbeat(): void
    {
        $recording = new PveStoreRecording();
        $this->store($this->database(['run_endpoint' => null], $recording))->recordEndpointAttempt(
            $this->lease(),
            new PveEndpointAttempt(
                $this->id('a'), $this->id('r'), $this->id('c'), $this->id('e'), 1,
                EndpointAttemptOutcome::Failover, 'transport_failure', $this->now(), $this->now(),
            ),
        );

        self::assertSame(['heartbeat_at' => self::NOW], $recording->updates[0][1]);
    }

    public function testRawDiskWriteStateRequiresAnAuthoritativeGuestScope(): void
    {
        $authoritative = new PveStoreRecording();
        $this->store($this->database(recording: $authoritative))->apply(
            $this->lease(),
            $this->commit(authoritative: true, diskWriteBytes: 123),
        );
        $guestWriteStatements = array_values(array_filter(
            $authoritative->statements,
            static fn (array $statement): bool => str_contains($statement[0], 'guest_write_states'),
        ));
        self::assertCount(1, $guestWriteStatements);
        self::assertSame(123, $guestWriteStatements[0][1]['diskwrite_bytes']);
        self::assertSame($this->id('r')->binary(), $guestWriteStatements[0][1]['authoritative_sync_run_id']);

        $partial = new PveStoreRecording();
        $this->store($this->database([
            'binding' => $this->clusterBindingRow(),
            'cluster' => ['id' => $this->id('k')->binary()],
            'node' => ['id' => $this->id('n')->binary()],
            'guest' => ['id' => $this->id('g')->binary()],
            'known_nodes' => [1, 'node-a'],
        ], $partial))->apply(
            $this->lease(),
            $this->commit(authoritative: false, diskWriteBytes: 123),
        );
        self::assertNotContains(
            true,
            array_map(
                static fn (array $statement): bool => str_contains($statement[0], 'guest_write_states'),
                $partial->statements,
            ),
        );
    }

    public function testProvisionedGuestSizeIsWrittenExactlyOnCreateAndUpdate(): void
    {
        $created = new PveStoreRecording();
        $this->store($this->database(recording: $created))->apply(
            $this->lease(),
            $this->commit(authoritative: true, provisionedSizeBytes: 4096),
        );
        $guestInsert = array_values(array_filter(
            $created->inserts,
            static fn (array $insert): bool => 'guests' === $insert[0],
        ));
        self::assertCount(1, $guestInsert);
        self::assertSame(4096, $guestInsert[0][1]['provisioned_size_bytes']);

        $updated = new PveStoreRecording();
        $this->store($this->database([
            'binding' => $this->clusterBindingRow(),
            'cluster' => ['id' => $this->id('k')->binary()],
            'node' => ['id' => $this->id('n')->binary()],
            'guest' => ['id' => $this->id('g')->binary()],
            'known_nodes' => [1, 'node-a'],
        ], $updated))->apply(
            $this->lease(),
            $this->commit(authoritative: true, provisionedSizeBytes: 0),
        );
        $guestUpdates = array_values(array_filter(
            $updated->updates,
            static fn (array $update): bool => 'guests' === $update[0],
        ));
        self::assertCount(1, $guestUpdates);
        self::assertSame(0, $guestUpdates[0][1]['provisioned_size_bytes']);
    }

    public function testExistingInventoryUpdatesAllAggregatesAndArchivesAuthoritativeAbsence(): void
    {
        $recording = new PveStoreRecording();
        $result = $this->store($this->database([
            'binding' => $this->clusterBindingRow(),
            'cluster' => ['id' => $this->id('k')->binary()],
            'node' => ['id' => $this->id('n')->binary()],
            'guest' => ['id' => $this->id('g')->binary()],
            'storages' => [
                'local' => ['id' => $this->id('d')->binary()],
                'pbs-store' => ['id' => $this->id('f')->binary()],
            ],
            'known_nodes' => [1, 'node-a'],
            'unseen_guests' => [['id' => $this->id('u')->binary()]],
            'unseen_nodes' => [['id' => $this->id('v')->binary()]],
            'unseen_storages' => [['id' => $this->id('q')->binary()]],
        ], $recording))->apply($this->lease(), $this->commit(authoritative: true, withStorage: true));

        self::assertSame(0, $result->created);
        self::assertSame(5, $result->updated);
        self::assertSame(3, $result->archived);
        self::assertSame(
            [
                'pve_storage_pbs_mappings',
                'pve_node_storage_state',
                'pve_storage_pbs_mappings',
                'pve_node_storage_state',
            ],
            array_column($recording->deletes, 0),
        );
    }

    public function testExistingStandaloneBindingSkipsClusterMembershipLookup(): void
    {
        $result = $this->store($this->database([
            'binding' => [
                'product' => 'pve',
                'identity_kind' => 'pve_standalone',
                'identity_value' => 'standalone',
            ],
            'cluster' => ['id' => $this->id('k')->binary()],
            'node' => ['id' => $this->id('n')->binary()],
            'guest' => ['id' => $this->id('g')->binary()],
        ]))->apply($this->lease(), $this->commit(authoritative: false, standalone: true, template: null));

        self::assertSame('partial', $result->status);
        self::assertFalse($result->diagnosticOnly);
    }

    public function testNewBindingsCoverExistingClusterAndStandaloneNullableGuestEvidence(): void
    {
        $existingCluster = $this->store($this->database([
            'cluster' => ['id' => $this->id('k')->binary()],
        ]))->apply(
            $this->lease(),
            $this->commit(authoritative: true, template: null),
        );
        self::assertSame(2, $existingCluster->created);
        self::assertSame(1, $existingCluster->updated);

        $standalone = $this->store($this->database())->apply(
            $this->lease(),
            $this->commit(authoritative: true, standalone: true, template: null),
        );
        self::assertSame(3, $standalone->created);
    }

    public function testFirstPartialAndExistingEmptySnapshotsRemainDiagnosticOnly(): void
    {
        $first = $this->store($this->database())->apply(
            $this->lease(),
            $this->commit(authoritative: false),
        );
        self::assertTrue($first->diagnosticOnly);
        self::assertSame('partial', $first->status);

        $existing = $this->store($this->database(['binding' => $this->clusterBindingRow()]))->apply(
            $this->lease(),
            $this->commit(authoritative: false, empty: true),
        );
        self::assertTrue($existing->diagnosticOnly);

        $failed = $this->store($this->database())->apply(
            $this->lease(),
            $this->commit(authoritative: false, empty: true, failed: true),
        );
        self::assertSame('failed', $failed->status);
    }

    public function testFinishWithoutSnapshotUsesObservedOrChangedCode(): void
    {
        $normal = new PveStoreRecording();
        $this->store($this->database(recording: $normal))->finishWithoutSnapshot($this->lease(), $this->failure());
        self::assertSame('endpoints_exhausted', $normal->updates[0][1]['error_code']);

        $changed = new PveStoreRecording();
        $this->store($this->database(['connection_product' => 'pbs'], $changed))->finishWithoutSnapshot(
            $this->lease(),
            $this->failure(),
        );
        self::assertSame('connection_changed', $changed->updates[0][1]['error_code']);
    }

    public function testEndpointRunAndFinishConflictsAbort(): void
    {
        $cases = [
            'endpoint already selected' => [
                [],
                function (DbalPveCoreInventoryStore $store): void {
                    $store->recordEndpointAttempt($this->lease(), $this->attempt());
                },
                PveCoreInventoryConflict::class,
            ],
            'endpoint not owned' => [
                ['run_endpoint' => null, 'endpoint' => false],
                function (DbalPveCoreInventoryStore $store): void {
                    $store->recordEndpointAttempt($this->lease(), $this->attempt());
                },
                PveCoreInventoryConflict::class,
            ],
            'finish already applied' => [
                ['run_applied_at' => self::NOW],
                function (DbalPveCoreInventoryStore $store): void {
                    $store->finishWithoutSnapshot($this->lease(), $this->failure());
                },
                PveCoreInventoryConflict::class,
            ],
            'finish revision mismatch' => [
                ['run_revision' => 2],
                function (DbalPveCoreInventoryStore $store): void {
                    $store->finishWithoutSnapshot($this->lease(), $this->failure());
                },
                PveCoreInventoryConflict::class,
            ],
            'finish exactly once' => [
                ['run_update_count' => 0],
                function (DbalPveCoreInventoryStore $store): void {
                    $store->finishWithoutSnapshot($this->lease(), $this->failure());
                },
                PveCoreInventoryConflict::class,
            ],
            'run ownership' => [
                ['run_status' => 'failed'],
                function (DbalPveCoreInventoryStore $store): void {
                    $store->apply($this->lease(), $this->commit(authoritative: true));
                },
                CollectorLeaseOwnershipLost::class,
            ],
        ];

        foreach ($cases as $name => [$configuration, $operation, $expected]) {
            try {
                $operation($this->store($this->database($configuration)));
                self::fail(sprintf('The %s conflict was accepted.', $name));
            } catch (PveCoreInventoryConflict|CollectorLeaseOwnershipLost $exception) {
                self::assertInstanceOf($expected, $exception, $name);
            }
        }
    }

    public function testApplyRejectsStaleSelectionConnectionBindingAndUnknownMembership(): void
    {
        $cases = [
            'already applied' => ['run_applied_at' => self::NOW],
            'revision mismatch' => ['run_revision' => 2],
            'connection changed' => ['connection_revision' => 2],
            'endpoint type' => ['run_endpoint' => 1],
            'endpoint length' => ['run_endpoint' => 'short'],
            'endpoint mismatch' => ['run_endpoint' => $this->id('x')->binary()],
            'selection missing' => ['selected_count' => 0],
            'binding product' => ['binding' => [...$this->clusterBindingRow(), 'product' => 'pbs']],
            'binding kind' => ['binding' => [...$this->clusterBindingRow(), 'identity_kind' => 'pve_standalone']],
            'binding value' => ['binding' => [...$this->clusterBindingRow(), 'identity_value' => 'other']],
            'unknown membership' => [
                'binding' => $this->clusterBindingRow(),
                'cluster' => ['id' => $this->id('k')->binary()],
                'known_nodes' => [1, 'other-node'],
            ],
        ];

        foreach ($cases as $name => $configuration) {
            try {
                $this->store($this->database($configuration))->apply(
                    $this->lease(),
                    $this->commit(authoritative: true),
                );
                self::fail(sprintf('The %s conflict was accepted.', $name));
            } catch (PveCoreInventoryConflict) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testInvalidDatabaseValuesFailClosedAndNumericStringsNormalize(): void
    {
        foreach ([
            ['now' => 1],
            ['now' => 'invalid'],
            ['connection_enabled' => 'yes'],
            ['selected_count' => 'one'],
            ['cluster' => ['id' => 'short']],
            [
                'binding' => $this->clusterBindingRow(),
                'cluster' => ['id' => $this->id('k')->binary()],
                'node' => ['id' => 'short'],
                'known_nodes' => ['node-a'],
            ],
            [
                'binding' => $this->clusterBindingRow(),
                'cluster' => ['id' => $this->id('k')->binary()],
                'node' => ['id' => $this->id('n')->binary()],
                'guest' => ['id' => 'short'],
                'known_nodes' => ['node-a'],
            ],
        ] as $configuration) {
            try {
                $this->store($this->database($configuration))->apply(
                    $this->lease(),
                    $this->commit(authoritative: true),
                );
                self::fail('The invalid database value was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $result = $this->store($this->database([
            'schedule_fence' => '1',
            'cycle_fence' => '1',
            'run_fence' => '1',
            'run_revision' => '1',
            'connection_enabled' => '1',
            'connection_revision' => '1',
            'selected_count' => '1',
        ]))->apply($this->lease(), $this->commit(authoritative: true));
        self::assertSame('succeeded', $result->status);
    }

    public function testInvalidFenceRowsAndFinalFenceRecheckLoseOwnership(): void
    {
        foreach ([
            ['schedule' => false], ['cycle' => false],
            ['schedule_owner' => $this->id('x')->binary()],
            ['schedule_token' => $this->id('x')->binary()],
            ['schedule_fence' => 2], ['schedule_expiry' => self::NOW],
            ['cycle_status' => 'finished'], ['cycle_owner' => $this->id('x')->binary()],
            ['cycle_kind' => 'backup'], ['cycle_fence' => 2],
        ] as $configuration) {
            try {
                $this->store($this->database($configuration))->beginRun(
                    $this->lease(),
                    new PveSyncRunStart($this->id('r'), $this->id('c'), 1, $this->now()),
                );
                self::fail('The invalid fence was accepted.');
            } catch (CollectorLeaseOwnershipLost) {
                self::addToAssertionCount(1);
            }
        }

        $recording = new PveStoreRecording();
        try {
            $this->store($this->database(['lose_fence_on_recheck' => true], $recording))->beginRun(
                $this->lease(),
                new PveSyncRunStart($this->id('r'), $this->id('c'), 1, $this->now()),
            );
            self::fail('The lost commit fence was accepted.');
        } catch (CollectorLeaseOwnershipLost) {
            self::assertSame(['inventory_sync_runs'], array_column($recording->inserts, 0));
        }
    }

    private function attempt(): PveEndpointAttempt
    {
        return new PveEndpointAttempt(
            $this->id('a'), $this->id('r'), $this->id('c'), $this->id('e'), 1,
            EndpointAttemptOutcome::Selected, null, $this->now(), $this->now(),
        );
    }

    private function failure(): PveSyncRunFailure
    {
        return new PveSyncRunFailure(
            $this->id('r'), $this->id('c'), 1,
            ConnectionReadFailureCode::EndpointsExhausted, $this->now(),
        );
    }

    private function commit(
        bool $authoritative,
        bool $withStorage = false,
        bool $standalone = false,
        bool $empty = false,
        bool $failed = false,
        ?bool $template = false,
        ?int $diskWriteBytes = null,
        ?int $provisionedSizeBytes = null,
    ): PveInventoryCommit {
        $status = $failed
            ? InventoryScopeStatus::Failed
            : ($authoritative ? InventoryScopeStatus::Complete : InventoryScopeStatus::Partial);
        $nodeName = $standalone ? 'standalone' : 'node-a';
        $nodes = $empty ? [] : [new PveNodeObservation($nodeName, 'online')];
        $guests = $empty ? [] : [new PveGuestObservation(
            PveGuestType::Qemu,
            100,
            $nodeName,
            'guest',
            $template,
            $diskWriteBytes,
            $provisionedSizeBytes,
        )];
        $binding = new PveCoreInstallationBinding(
            $standalone ? PveCoreBindingKind::Standalone : PveCoreBindingKind::Cluster,
            $standalone ? 'standalone' : 'cluster-a',
        );
        $core = new PveCoreInventoryCommit(
            $this->id('r'), $this->id('c'), $this->id('e'), 1, $binding,
            new PveCoreScopeResult(PveCoreScope::Topology, $status),
            new PveCoreScopeResult(PveCoreScope::Guests, $status),
            $nodes, $guests, $this->now(),
        );

        $storages = [];
        $states = [];
        if ($withStorage) {
            $storages = [
                new PveStorageObservation(
                    'local', 'dir', ['backup'], [$nodeName], false, false, null, $this->now(),
                ),
                new PveStorageObservation(
                    'pbs-store', 'pbs', ['backup'], null, true, true,
                    new PvePbsStorageMapping('pbs.example', 8007, 'backup', 'namespace'),
                    $this->now(),
                ),
            ];
            $states = [new PveNodeStorageStateObservation(
                $nodeName, 'local', true, true, false, PveStorageCapacityStatus::Measured,
                100, 20, 80, $this->now(),
            )];
        }

        return new PveInventoryCommit(
            $core,
            new PveCoreScopeResult(PveCoreScope::Storages, $status),
            $empty ? [] : [new PveNodeStorageScopeResult($nodeName, $status)],
            $storages,
            $states,
        );
    }

    /** @return array<string, mixed> */
    private function clusterBindingRow(): array
    {
        return ['product' => 'pve', 'identity_kind' => 'pve_cluster', 'identity_value' => 'cluster-a'];
    }

    private function store(Connection $database): DbalPveCoreInventoryStore
    {
        return new DbalPveSequenceIdentifierGenerator([
            $this->id('k'), $this->id('n'), $this->id('g'), $this->id('d'), $this->id('f'),
        ])->store($database);
    }

    /** @param array<string, mixed> $configuration */
    private function database(array $configuration = [], ?PveStoreRecording $recording = null): Connection&MockObject
    {
        $configuration += [
            'now' => self::NOW,
            'schedule' => true, 'schedule_owner' => $this->id('w')->binary(),
            'schedule_token' => $this->id('t')->binary(), 'schedule_fence' => 1,
            'schedule_expiry' => '2026-07-11 12:10:00.000000',
            'cycle' => true, 'cycle_status' => 'running', 'cycle_owner' => $this->id('w')->binary(),
            'cycle_kind' => 'collector', 'cycle_fence' => 1,
            'run_status' => 'running', 'run_token' => $this->id('t')->binary(), 'run_fence' => 1,
            'run_revision' => 1, 'run_endpoint' => $this->id('e')->binary(), 'run_applied_at' => null,
            'connection_product' => 'pve', 'connection_enabled' => 1, 'connection_revision' => 1,
            'endpoint' => ['id' => $this->id('e')->binary()], 'selected_count' => 1,
            'binding' => false, 'cluster' => false, 'node' => false, 'guest' => false,
            'storages' => [], 'known_nodes' => [],
            'unseen_guests' => [], 'unseen_nodes' => [], 'unseen_storages' => [],
            'run_update_count' => 1, 'lose_fence_on_recheck' => false,
        ];
        $recording ??= new PveStoreRecording();
        $scheduleReads = 0;
        $database = $this->createMock(Connection::class);
        $database->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($database),
        );
        $database->method('fetchOne')->willReturnCallback(
            static function (string $sql) use ($configuration): mixed {
                if ('SELECT UTC_TIMESTAMP(6)' === $sql) {
                    return $configuration['now'];
                }
                if (str_contains($sql, 'inventory_sync_endpoint_attempts')) {
                    return $configuration['selected_count'];
                }
                return 0;
            },
        );
        $database->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $parameters = []) use ($configuration, &$scheduleReads): array|false {
                if (str_contains($sql, 'collector_schedule')) {
                    if (false === $configuration['schedule']) {
                        return false;
                    }
                    ++$scheduleReads;
                    return [
                        'lease_owner' => true === $configuration['lose_fence_on_recheck'] && $scheduleReads > 1
                            ? str_repeat('x', 16) : $configuration['schedule_owner'],
                        'lease_token' => $configuration['schedule_token'],
                        'lease_fencing_token' => $configuration['schedule_fence'],
                        'lease_expires_at' => $configuration['schedule_expiry'],
                    ];
                }
                if (str_contains($sql, 'collector_cycles')) {
                    if (false === $configuration['cycle']) {
                        return false;
                    }
                    return [
                        'status' => $configuration['cycle_status'], 'worker_instance_id' => $configuration['cycle_owner'],
                        'worker_kind' => $configuration['cycle_kind'], 'fencing_token' => $configuration['cycle_fence'],
                    ];
                }
                if (str_contains($sql, 'inventory_sync_runs')) {
                    return [
                        'status' => $configuration['run_status'], 'cycle_token' => $configuration['run_token'],
                        'collector_fencing_token' => $configuration['run_fence'],
                        'expected_connection_revision' => $configuration['run_revision'],
                        'endpoint_id' => $configuration['run_endpoint'], 'applied_at' => $configuration['run_applied_at'],
                    ];
                }
                if (str_contains($sql, 'proxmox_connections')) {
                    return [
                        'product' => $configuration['connection_product'], 'enabled' => $configuration['connection_enabled'],
                        'revision' => $configuration['connection_revision'],
                    ];
                }
                foreach ([
                    'proxmox_connection_endpoints' => 'endpoint',
                    'proxmox_installation_bindings' => 'binding',
                    'pve_clusters' => 'cluster',
                ] as $needle => $key) {
                    if (str_contains($sql, $needle)) {
                        $row = $configuration[$key];
                        assert(false === $row || is_array($row));
                        return $row;
                    }
                }
                if (str_contains($sql, 'FROM guests')) {
                    $row = $configuration['guest'];
                    assert(false === $row || is_array($row));
                    return $row;
                }
                if (str_contains($sql, 'pve_storages')) {
                    $rows = $configuration['storages'];
                    assert(is_array($rows));
                    $name = $parameters['name'] ?? null;
                    $row = is_string($name) ? ($rows[$name] ?? false) : false;
                    assert(false === $row || is_array($row));
                    return $row;
                }
                if (str_contains($sql, 'pve_nodes')) {
                    $row = $configuration['node'];
                    assert(false === $row || is_array($row));
                    return $row;
                }
                return false;
            },
        );
        $database->method('fetchFirstColumn')->willReturnCallback(
            static function () use ($configuration): array {
                $known = $configuration['known_nodes'];
                assert(is_array($known));
                return $known;
            },
        );
        $database->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($configuration): array {
                $key = str_contains($sql, 'FROM guests') ? 'unseen_guests'
                    : (str_contains($sql, 'pve_nodes') ? 'unseen_nodes' : 'unseen_storages');
                $rows = $configuration[$key];
                assert(is_array($rows));
                return $rows;
            },
        );
        $database->method('insert')->willReturnCallback(
            static function (string $table, array $values) use ($recording): int {
                /** @var array<string, mixed> $values */
                $recording->inserts[] = [$table, $values];
                return 1;
            },
        );
        $database->method('update')->willReturnCallback(
            static function (string $table, array $values, array $criteria) use ($configuration, $recording): int {
                /** @var array<string, mixed> $values */
                /** @var array<string, mixed> $criteria */
                $recording->updates[] = [$table, $values, $criteria];
                $count = 'inventory_sync_runs' === $table ? $configuration['run_update_count'] : 1;
                assert(is_int($count));
                return $count;
            },
        );
        $database->method('delete')->willReturnCallback(
            static function (string $table, array $criteria) use ($recording): int {
                /** @var array<string, mixed> $criteria */
                $recording->deletes[] = [$table, $criteria];
                return 1;
            },
        );
        $database->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters = []) use ($recording): int {
                /** @var array<string, mixed> $parameters */
                $recording->statements[] = [$sql, $parameters];
                return 1;
            },
        );
        return $database;
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId($this->id('w')->binary()),
            new CollectorCycleToken($this->id('t')->binary()), 1,
            new DateTimeImmutable('2026-07-11T12:10:00+00:00'),
        );
    }

    private function id(string $byte): InventoryIdentifier
    {
        return new InventoryIdentifier(str_repeat($byte, 16));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-11T12:00:00+00:00');
    }
}

final class PveStoreRecording
{
    /** @var list<array{string, array<string, mixed>}> */ public array $inserts = [];
    /** @var list<array{string, array<string, mixed>, array<string, mixed>}> */ public array $updates = [];
    /** @var list<array{string, array<string, mixed>}> */ public array $deletes = [];
    /** @var list<array{string, array<string, mixed>}> */ public array $statements = [];
}

final class DbalPveSequenceIdentifierGenerator implements InventoryIdentifierGenerator
{
    /** @param list<InventoryIdentifier> $identifiers */
    public function __construct(private array $identifiers)
    {
    }

    public function store(Connection $database): DbalPveCoreInventoryStore
    {
        return new DbalPveCoreInventoryStore($database, $this);
    }

    public function generate(): InventoryIdentifier
    {
        $identifier = array_shift($this->identifiers);
        TestCase::assertInstanceOf(InventoryIdentifier::class, $identifier);
        return $identifier;
    }
}
