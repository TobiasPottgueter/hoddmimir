<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
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
use App\Application\Inventory\Pve\PveSyncRunFailure;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Proxmox\Pbs\PbsCapacitySemantics;
use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsMaintenanceMode;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Infrastructure\Persistence\MariaDb\DbalPbsInventoryStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalPbsInventoryStoreTest extends TestCase
{
    private const string NOW = '2026-07-11 12:00:00.000000';

    public function testLifecycleWritesACompleteNewInventoryThroughFencedTransactions(): void
    {
        $beginRecording = new PbsStoreRecording();
        $this->store($this->database(recording: $beginRecording))->beginRun(
            $this->lease(),
            new PveSyncRunStart($this->id('r'), $this->id('c'), 1, $this->now()),
        );
        self::assertSame(['inventory_sync_runs'], array_column($beginRecording->inserts, 0));

        $attemptRecording = new PbsStoreRecording();
        $this->store($this->database(['run_endpoint' => null], $attemptRecording))->recordEndpointAttempt(
            $this->lease(),
            new PveEndpointAttempt(
                $this->id('a'),
                $this->id('r'),
                $this->id('c'),
                $this->id('e'),
                1,
                EndpointAttemptOutcome::Selected,
                null,
                $this->now(),
                $this->now(),
            ),
        );
        self::assertSame('selected', $attemptRecording->inserts[0][1]['outcome']);
        self::assertSame($this->id('e')->binary(), $attemptRecording->updates[0][1]['endpoint_id']);

        $applyRecording = new PbsStoreRecording();
        $result = $this->store($this->database(recording: $applyRecording))->apply(
            $this->lease(),
            $this->commit(authoritative: true, instanceBinding: true),
        );

        self::assertSame('succeeded', $result->status);
        self::assertSame(3, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->archived);
        self::assertFalse($result->diagnosticOnly);
        self::assertSame(
            [
                'proxmox_installation_bindings',
                'pbs_servers',
                'pbs_datastores',
                'pbs_datastores',
                'inventory_sync_scope_results',
                'inventory_sync_scope_results',
                'inventory_sync_scope_results',
                'inventory_sync_scope_results',
            ],
            array_column($applyRecording->inserts, 0),
        );
        self::assertCount(3, $applyRecording->statements);
    }

    public function testNonSelectedAttemptOnlyAdvancesHeartbeat(): void
    {
        $recording = new PbsStoreRecording();
        $this->store($this->database(['run_endpoint' => null], $recording))->recordEndpointAttempt(
            $this->lease(),
            new PveEndpointAttempt(
                $this->id('a'),
                $this->id('r'),
                $this->id('c'),
                $this->id('e'),
                1,
                EndpointAttemptOutcome::Failover,
                'transport_failure',
                $this->now(),
                $this->now(),
            ),
        );

        self::assertSame(['heartbeat_at' => self::NOW], $recording->updates[0][1]);
    }

    public function testExistingPartialInventoryUpdatesOnlyPositiveEvidence(): void
    {
        $recording = new PbsStoreRecording();
        $database = $this->database([
            'binding' => $this->legacyBindingRow(),
            'server' => ['id' => $this->id('s')->binary()],
            'stores' => [
                'store_a' => ['id' => $this->id('d')->binary(), 'backend_type' => 'filesystem'],
                'store_b' => ['id' => $this->id('f')->binary(), 'backend_type' => 's3'],
            ],
        ], $recording);

        $result = $this->store($database)->apply(
            $this->lease(),
            $this->commit(authoritative: false, status: false),
        );

        self::assertSame('partial', $result->status);
        self::assertSame(0, $result->created);
        self::assertSame(3, $result->updated);
        self::assertSame(0, $result->archived);
        self::assertFalse($result->diagnosticOnly);
        self::assertSame([], $recording->deletes);
        self::assertCount(2, $recording->statements);
    }

    public function testAuthoritativeInventoryUpgradesLegacyBindingReplacesBackendAndArchivesAbsence(): void
    {
        $recording = new PbsStoreRecording();
        $database = $this->database([
            'binding' => $this->legacyBindingRow(),
            'server' => ['id' => $this->id('s')->binary()],
            'stores' => [
                'store_a' => ['id' => $this->id('d')->binary(), 'backend_type' => 's3'],
                'store_b' => ['id' => $this->id('f')->binary(), 'backend_type' => 's3'],
            ],
            'unseen' => [['id' => $this->id('u')->binary()]],
        ], $recording);

        $result = $this->store($database)->apply(
            $this->lease(),
            $this->commit(authoritative: true, instanceBinding: true),
        );

        self::assertSame('succeeded', $result->status);
        self::assertSame(0, $result->created);
        self::assertSame(3, $result->updated);
        self::assertSame(1, $result->archived);
        self::assertSame(
            ['pbs_datastore_capacity_state', 'pbs_datastore_capacity_state'],
            array_column($recording->deletes, 0),
        );
        self::assertSame('pbs_instance', $recording->updates[0][1]['identity_kind']);
        self::assertNull($recording->updates[0][1]['legacy_endpoint_id']);
    }

    public function testFirstPartialInventoryIsDiagnosticOnlyButPersistsScopesAndRun(): void
    {
        $recording = new PbsStoreRecording();
        $result = $this->store($this->database(recording: $recording))->apply(
            $this->lease(),
            $this->commit(authoritative: false),
        );

        self::assertSame('partial', $result->status);
        self::assertTrue($result->diagnosticOnly);
        self::assertSame(0, $result->created);
        self::assertSame(
            [
                'inventory_sync_scope_results',
                'inventory_sync_scope_results',
                'inventory_sync_scope_results',
                'inventory_sync_scope_results',
            ],
            array_column($recording->inserts, 0),
        );
    }

    public function testFinishWithoutSnapshotUsesObservedOrChangedFailureCode(): void
    {
        $normal = new PbsStoreRecording();
        $this->store($this->database(recording: $normal))->finishWithoutSnapshot(
            $this->lease(),
            $this->failure(),
        );
        self::assertSame('endpoints_exhausted', $normal->updates[0][1]['error_code']);

        $changed = new PbsStoreRecording();
        $this->store($this->database(['connection_product' => 'pve'], $changed))->finishWithoutSnapshot(
            $this->lease(),
            $this->failure(),
        );
        self::assertSame('connection_changed', $changed->updates[0][1]['error_code']);
    }

    public function testRunAndEndpointConflictsAbortTheirTransactions(): void
    {
        $cases = [
            'attempt already selected' => [
                ['run_endpoint' => $this->id('e')->binary()],
                function (DbalPbsInventoryStore $store): void {
                    $store->recordEndpointAttempt($this->lease(), $this->attempt());
                },
                PbsInventoryConflict::class,
            ],
            'attempt endpoint not owned' => [
                ['run_endpoint' => null, 'owned_count' => 0],
                function (DbalPbsInventoryStore $store): void {
                    $store->recordEndpointAttempt($this->lease(), $this->attempt());
                },
                PbsInventoryConflict::class,
            ],
            'already applied finish' => [
                ['run_applied_at' => self::NOW],
                function (DbalPbsInventoryStore $store): void {
                    $store->finishWithoutSnapshot($this->lease(), $this->failure());
                },
                PbsInventoryConflict::class,
            ],
            'finish revision mismatch' => [
                ['run_revision' => 2],
                function (DbalPbsInventoryStore $store): void {
                    $store->finishWithoutSnapshot($this->lease(), $this->failure());
                },
                PbsInventoryConflict::class,
            ],
            'finish exactly once' => [
                ['run_update_count' => 0],
                function (DbalPbsInventoryStore $store): void {
                    $store->finishWithoutSnapshot($this->lease(), $this->failure());
                },
                PbsInventoryConflict::class,
            ],
            'lease no longer owns run' => [
                ['run_status' => 'failed'],
                function (DbalPbsInventoryStore $store): void {
                    $store->apply($this->lease(), $this->commit(authoritative: true));
                },
                CollectorLeaseOwnershipLost::class,
            ],
        ];

        foreach ($cases as $name => [$configuration, $operation, $exception]) {
            try {
                $operation($this->store($this->database($configuration)));
                self::fail(sprintf('The %s conflict was accepted.', $name));
            } catch (PbsInventoryConflict|CollectorLeaseOwnershipLost $caught) {
                self::assertInstanceOf($exception, $caught, $name);
            }
        }
    }

    public function testApplyRejectsStaleRunsConnectionsEndpointsAndBindings(): void
    {
        $cases = [
            'already applied' => ['run_applied_at' => self::NOW],
            'revision differs from run' => ['run_revision' => 2],
            'connection changed' => ['connection_revision' => 2],
            'endpoint differs from run' => ['run_endpoint' => $this->id('x')->binary()],
            'endpoint lacks selected attempt' => ['selected_count' => 0],
            'binding has wrong product' => ['binding' => [...$this->legacyBindingRow(), 'product' => 'pve']],
            'binding differs' => ['binding' => [...$this->legacyBindingRow(), 'identity_value' => 'other-node']],
            'apply exactly once' => ['run_update_count' => 0],
        ];

        foreach ($cases as $name => $configuration) {
            try {
                $this->store($this->database($configuration))->apply(
                    $this->lease(),
                    $this->commit(authoritative: true),
                );
                self::fail(sprintf('The %s conflict was accepted.', $name));
            } catch (PbsInventoryConflict $exception) {
                self::addToAssertionCount(1);
                if ('connection changed' === $name) {
                    self::assertTrue($exception->connectionChanged);
                }
            }
        }
    }

    public function testInvalidDatabaseValuesFailClosed(): void
    {
        $cases = [
            'clock type' => ['now' => 1],
            'clock syntax' => ['now' => 'invalid'],
            'integer' => ['connection_enabled' => 'yes'],
            'count' => ['selected_count' => 'one'],
            'server binary id' => ['server' => ['id' => 'short']],
            'archive binary id' => ['unseen' => [['id' => 'short']]],
        ];

        foreach ($cases as $name => $configuration) {
            try {
                $this->store($this->database($configuration))->apply(
                    $this->lease(),
                    $this->commit(authoritative: true),
                );
                self::fail(sprintf('The invalid %s value was accepted.', $name));
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNumericDatabaseStringsAreNormalized(): void
    {
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

    public function testFenceLossDuringFinalRecheckAbortsAfterTheTentativeWrite(): void
    {
        $recording = new PbsStoreRecording();
        $store = $this->store($this->database(['lose_fence_on_recheck' => true], $recording));

        try {
            $store->beginRun(
                $this->lease(),
                new PveSyncRunStart($this->id('r'), $this->id('c'), 1, $this->now()),
            );
            self::fail('The lost commit fence was accepted.');
        } catch (CollectorLeaseOwnershipLost) {
            self::assertSame(['inventory_sync_runs'], array_column($recording->inserts, 0));
        }
    }

    public function testInvalidFenceRowsLoseLeaseOwnership(): void
    {
        foreach ([
            ['schedule' => false],
            ['cycle' => false],
            ['schedule_owner' => $this->id('x')->binary()],
            ['schedule_token' => $this->id('x')->binary()],
            ['schedule_fence' => 2],
            ['schedule_expiry' => self::NOW],
            ['cycle_status' => 'finished'],
            ['cycle_owner' => $this->id('x')->binary()],
            ['cycle_kind' => 'backup'],
            ['cycle_fence' => 2],
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
    }

    private function attempt(): PveEndpointAttempt
    {
        return new PveEndpointAttempt(
            $this->id('a'),
            $this->id('r'),
            $this->id('c'),
            $this->id('e'),
            1,
            EndpointAttemptOutcome::Selected,
            null,
            $this->now(),
            $this->now(),
        );
    }

    private function failure(): PveSyncRunFailure
    {
        return new PveSyncRunFailure(
            $this->id('r'),
            $this->id('c'),
            1,
            ConnectionReadFailureCode::EndpointsExhausted,
            $this->now(),
        );
    }

    private function commit(
        bool $authoritative,
        bool $instanceBinding = false,
        bool $status = true,
    ): PbsInventoryCommit {
        $scopeStatus = $authoritative ? InventoryScopeStatus::Complete : InventoryScopeStatus::Partial;
        $serverStatus = $status ? new PbsNodeStatus('pbs-node', 100, 1000, 300, 2000, 500, 1500) : null;
        $stores = [
            new PbsDatastoreObservation(
                'store_a',
                PbsDatastoreBackendType::Filesystem,
                PbsMountStatus::Mounted,
                null,
                true,
            ),
            new PbsDatastoreObservation(
                'store_b',
                PbsDatastoreBackendType::S3,
                PbsMountStatus::NonRemovable,
                PbsMaintenanceMode::ReadOnly,
                false,
            ),
        ];
        $capacities = [
            new PbsCapacityObservation(
                'store_a',
                PbsDatastoreBackendType::Filesystem,
                PbsCapacitySemantics::DatastoreFilesystem,
                100,
                20,
                80,
            ),
            new PbsCapacityObservation(
                'store_b',
                PbsDatastoreBackendType::S3,
                PbsCapacitySemantics::LocalCache,
                200,
                50,
                150,
            ),
        ];
        $binding = $instanceBinding
            ? InstallationBinding::pbsInstance(str_repeat('a', 32))
            : InstallationBinding::pbsLegacyNode('pbs-node', new EndpointId($this->id('e')->binary()));

        return new PbsInventoryCommit(
            $this->id('r'),
            $this->id('c'),
            $this->id('e'),
            1,
            $binding,
            new PbsInventoryScopeResult(PbsInventoryScope::System, '@installation', $scopeStatus),
            new PbsInventoryScopeResult(PbsInventoryScope::Datastores, '@installation', $scopeStatus),
            [
                new PbsInventoryScopeResult(PbsInventoryScope::DatastoreStatus, 'store_a', $scopeStatus),
                new PbsInventoryScopeResult(PbsInventoryScope::DatastoreStatus, 'store_b', $scopeStatus),
            ],
            new PbsServerObservation(
                'pbs-node',
                new PbsVersion($instanceBinding ? 4 : 3, $instanceBinding ? 2 : 4, 1, 'version', 'release', 'repo'),
                $serverStatus,
            ),
            $stores,
            $capacities,
            $this->now(),
        );
    }

    /** @return array<string, mixed> */
    private function legacyBindingRow(): array
    {
        return [
            'product' => 'pbs',
            'identity_kind' => 'pbs_legacy_node',
            'identity_value' => 'pbs-node',
            'legacy_endpoint_id' => $this->id('e')->binary(),
        ];
    }

    private function store(Connection $database): DbalPbsInventoryStore
    {
        return new DbalPbsInventoryStore($database, new PbsSequenceIdentifierGenerator([
            $this->id('s'),
            $this->id('d'),
            $this->id('f'),
        ]));
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function database(
        array $configuration = [],
        ?PbsStoreRecording $recording = null,
    ): Connection&MockObject {
        $configuration += [
            'now' => self::NOW,
            'schedule' => true,
            'schedule_owner' => $this->id('w')->binary(),
            'schedule_token' => $this->id('t')->binary(),
            'schedule_fence' => 1,
            'schedule_expiry' => '2026-07-11 12:10:00.000000',
            'cycle' => true,
            'cycle_status' => 'running',
            'cycle_owner' => $this->id('w')->binary(),
            'cycle_kind' => 'collector',
            'cycle_fence' => 1,
            'run_status' => 'running',
            'run_token' => $this->id('t')->binary(),
            'run_fence' => 1,
            'run_revision' => 1,
            'run_endpoint' => $this->id('e')->binary(),
            'run_applied_at' => null,
            'connection_product' => 'pbs',
            'connection_enabled' => 1,
            'connection_revision' => 1,
            'binding' => false,
            'server' => false,
            'stores' => [],
            'unseen' => [],
            'owned_count' => 1,
            'selected_count' => 1,
            'run_update_count' => 1,
            'lose_fence_on_recheck' => false,
        ];
        $recording ??= new PbsStoreRecording();
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
                if (str_contains($sql, 'proxmox_connection_endpoints')) {
                    return $configuration['owned_count'];
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
                            ? str_repeat('x', 16)
                            : $configuration['schedule_owner'],
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
                        'status' => $configuration['cycle_status'],
                        'worker_instance_id' => $configuration['cycle_owner'],
                        'worker_kind' => $configuration['cycle_kind'],
                        'fencing_token' => $configuration['cycle_fence'],
                    ];
                }
                if (str_contains($sql, 'inventory_sync_runs')) {
                    return [
                        'status' => $configuration['run_status'],
                        'cycle_token' => $configuration['run_token'],
                        'collector_fencing_token' => $configuration['run_fence'],
                        'expected_connection_revision' => $configuration['run_revision'],
                        'endpoint_id' => $configuration['run_endpoint'],
                        'applied_at' => $configuration['run_applied_at'],
                    ];
                }
                if (str_contains($sql, 'proxmox_connections')) {
                    return [
                        'product' => $configuration['connection_product'],
                        'enabled' => $configuration['connection_enabled'],
                        'revision' => $configuration['connection_revision'],
                    ];
                }
                if (str_contains($sql, 'proxmox_installation_bindings')) {
                    $binding = $configuration['binding'];
                    assert(false === $binding || is_array($binding));
                    return $binding;
                }
                if (str_contains($sql, 'pbs_servers')) {
                    $server = $configuration['server'];
                    assert(false === $server || is_array($server));
                    return $server;
                }
                if (str_contains($sql, 'pbs_datastores')) {
                    $name = $parameters['name'] ?? null;
                    $stores = $configuration['stores'];
                    assert(is_array($stores));
                    $store = is_string($name) ? ($stores[$name] ?? false) : false;
                    assert(false === $store || is_array($store));
                    return $store;
                }
                return false;
            },
        );
        $database->method('fetchAllAssociative')->willReturn($configuration['unseen']);
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
            static function (string $sql, array $parameters) use ($recording): int {
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
            new CollectorCycleToken($this->id('t')->binary()),
            1,
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

final class PbsStoreRecording
{
    /** @var list<array{string, array<string, mixed>}> */ public array $inserts = [];
    /** @var list<array{string, array<string, mixed>, array<string, mixed>}> */ public array $updates = [];
    /** @var list<array{string, array<string, mixed>}> */ public array $deletes = [];
    /** @var list<array{string, array<string, mixed>}> */ public array $statements = [];
}

final class PbsSequenceIdentifierGenerator implements InventoryIdentifierGenerator
{
    /** @param list<InventoryIdentifier> $identifiers */
    public function __construct(private array $identifiers)
    {
    }

    public function generate(): InventoryIdentifier
    {
        $identifier = array_shift($this->identifiers);
        TestCase::assertInstanceOf(InventoryIdentifier::class, $identifier);
        return $identifier;
    }
}
