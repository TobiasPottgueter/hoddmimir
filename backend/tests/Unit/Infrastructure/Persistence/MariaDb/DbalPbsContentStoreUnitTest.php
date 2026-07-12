<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\PbsContent\PbsContentCommit;
use App\Application\Inventory\PbsContent\PbsContentConflict;
use App\Application\Inventory\PbsContent\PbsContentRunFailure;
use App\Application\Inventory\PbsContent\PbsContentRunStart;
use App\Application\Inventory\PbsContent\PbsContentScopeResult;
use App\Application\Inventory\PbsContent\PbsContentScopeStatus;
use App\Application\Inventory\PbsContent\PbsContentScopeType;
use App\Application\Inventory\PbsContent\PbsContentSnapshot;
use App\Application\Inventory\PbsContent\PbsNamespaceObservation;
use App\Application\Proxmox\Pbs\PbsBackupType;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsSnapshotObservation;
use App\Infrastructure\Persistence\MariaDb\DbalPbsContentStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DbalPbsContentStoreUnitTest extends TestCase
{
    private const string AT = '2026-07-12 10:00:00.000000';

    public function testBeginCreateApplyArchiveAndFailLifecycle(): void
    {
        [$store, $database] = $this->store();
        $lease = $this->lease();
        $run = self::id('run');
        $parent = self::id('parent');
        $store->begin($lease, $this->start($run, $parent));

        $result = $store->apply($lease, $this->commit($run, $parent, $this->snapshot()));

        self::assertSame('succeeded', $result->status->value);
        self::assertSame(4, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(2, $result->archived);

        [$failStore] = $this->store();
        $failStore->begin($lease, $this->start($run, $parent));
        $failStore->fail($lease, new PbsContentRunFailure(
            $run,
            self::id('connection'),
            'read_failed',
            new DateTimeImmutable(self::AT.' UTC'),
        ));

        self::assertNotEmpty($database);
    }

    public function testExistingRowsAreUpdatedAndPartialScopesNeverArchive(): void
    {
        [$store] = $this->store(['existing' => true, 'archive_count' => 0]);
        $lease = $this->lease();
        $run = self::id('run-existing');
        $parent = self::id('parent');
        $store->begin($lease, $this->start($run, $parent));
        $snapshot = $this->snapshot(PbsContentScopeStatus::Partial, null);

        $result = $store->apply($lease, $this->commit($run, $parent, $snapshot));

        self::assertSame('partial', $result->status->value);
        self::assertSame(0, $result->created);
        self::assertSame(4, $result->updated);
        self::assertSame(0, $result->archived);
    }

    public function testEmptyCommitIsAnIdempotentSuccessfulNoop(): void
    {
        [$store] = $this->store();
        $lease = $this->lease();
        $run = self::id('empty-run');
        $parent = self::id('parent');
        $store->begin($lease, $this->start($run, $parent));

        $result = $store->apply($lease, $this->commit(
            $run,
            $parent,
            new PbsContentSnapshot([], [], []),
        ));

        self::assertSame('succeeded', $result->status->value);
        self::assertSame(0, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->archived);
    }

    public function testBeginConflictsAndFenceLossFailClosed(): void
    {
        $lease = $this->lease();
        $start = $this->start(self::id('run'), self::id('parent'));
        foreach ([
            ['begin_insert_failure' => true, 'expected' => PbsContentConflict::class],
            ['parent_valid' => false, 'expected' => PbsContentConflict::class],
            ['connection_valid' => false, 'expected' => PbsContentConflict::class],
            ['selected_count' => 0, 'expected' => PbsContentConflict::class],
            ['fence_valid' => false, 'expected' => CollectorLeaseOwnershipLost::class],
            ['clock' => false, 'expected' => \RuntimeException::class],
            ['clock' => 'not-a-date', 'expected' => \RuntimeException::class],
            ['fence_token' => 'bad', 'expected' => \RuntimeException::class],
            ['selected_count' => new \stdClass(), 'expected' => \RuntimeException::class],
        ] as $case) {
            $expected = $case['expected'];
            unset($case['expected']);
            [$store] = $this->store($case);
            try {
                $store->begin($lease, $start);
                self::fail('Invalid PBS content begin state accepted.');
            } catch (\Throwable $failure) {
                self::assertInstanceOf($expected, $failure);
            }
        }

        [$stringCountStore] = $this->store(['selected_count' => '1']);
        $stringCountStore->begin($lease, $start);
    }

    public function testFailRequiresExactlyOneTerminalTransition(): void
    {
        [$store] = $this->store(['run_update' => 0]);
        $this->expectException(PbsContentConflict::class);
        $store->fail($this->lease(), new PbsContentRunFailure(
            self::id('run'), self::id('connection'), 'failed', new DateTimeImmutable(self::AT.' UTC'),
        ));
    }

    public function testMalformedPersistenceRowsFailClosed(): void
    {
        foreach ([
            ['existing' => true, 'namespace_id' => 'short'],
            ['datastore_name' => 123],
        ] as $options) {
            [$store] = $this->store($options);
            try {
                $store->apply($this->lease(), $this->commit(
                    self::id('run'), self::id('parent'), $this->snapshot(),
                ));
                self::fail('Malformed PBS persistence row accepted.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testApplyConflictsFailClosedWithoutPartialWrites(): void
    {
        $lease = $this->lease();
        $run = self::id('run');
        $parent = self::id('parent');
        foreach ([
            ['child_valid' => false, 'snapshot' => $this->snapshot(), 'expected' => CollectorLeaseOwnershipLost::class],
            ['commit_matches' => false, 'snapshot' => $this->snapshot(), 'expected' => PbsContentConflict::class],
            ['datastores' => false, 'snapshot' => $this->snapshot(), 'expected' => PbsContentConflict::class],
            ['server_valid' => false, 'snapshot' => $this->snapshot(), 'expected' => PbsContentConflict::class],
            ['existing' => true, 'owner_conflict' => true, 'snapshot' => $this->snapshot(), 'expected' => PbsContentConflict::class],
            ['run_update' => 0, 'snapshot' => new PbsContentSnapshot([], [], []), 'expected' => PbsContentConflict::class],
        ] as $case) {
            $snapshot = $case['snapshot'];
            $expected = $case['expected'];
            unset($case['snapshot'], $case['expected']);
            [$store] = $this->store($case);
            try {
                $store->apply($lease, $this->commit($run, $parent, $snapshot));
                self::fail('Invalid PBS content apply state accepted.');
            } catch (\Throwable $failure) {
                self::assertInstanceOf($expected, $failure);
            }
        }

        [$failedStore] = $this->store();
        $failedSnapshot = new PbsContentSnapshot([], [], [new PbsContentScopeResult(
            PbsContentScopeType::Namespaces,
            new PbsDatastoreId('store_a'),
            null,
            PbsContentScopeStatus::Failed,
            0,
            'failed',
        )]);
        $this->expectException(PbsContentConflict::class);
        $failedStore->apply($lease, $this->commit($run, $parent, $failedSnapshot));
    }

    public function testScopeReferencesMustResolveInsideTheSelectedDatastore(): void
    {
        $lease = $this->lease();
        $run = self::id('scope-run');
        $parent = self::id('parent');
        $scope = new PbsContentScopeResult(
            PbsContentScopeType::Snapshots,
            new PbsDatastoreId('store_a'),
            PbsNamespace::root(),
            PbsContentScopeStatus::Complete,
            0,
        );
        foreach ([
            [['datastores' => false], 'datastore'],
            [[], 'namespace'],
        ] as [$options, $label]) {
            [$store] = $this->store($options);
            try {
                $store->apply($lease, $this->commit(
                    $run,
                    $parent,
                    new PbsContentSnapshot([], [], [$scope]),
                ));
                self::fail('Missing scope '.$label.' accepted.');
            } catch (PbsContentConflict) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDefensiveSnapshotAndNullableBindingGuardsCoverCorruptedBoundaries(): void
    {
        $valid = $this->snapshot();
        $reflection = new \ReflectionClass(PbsContentSnapshot::class);
        $corrupt = $reflection->newInstanceWithoutConstructor();
        self::assertInstanceOf(PbsContentSnapshot::class, $corrupt);
        $reflection->getProperty('namespaces')->setValue($corrupt, []);
        $reflection->getProperty('snapshots')->setValue($corrupt, $valid->snapshots);
        $reflection->getProperty('scopes')->setValue($corrupt, []);
        [$store] = $this->store();
        try {
            $store->apply($this->lease(), $this->commit(
                self::id('run'), self::id('parent'), $corrupt,
            ));
            self::fail('A corrupted snapshot boundary was accepted.');
        } catch (PbsContentConflict) {
            self::addToAssertionCount(1);
        }

        $sameNullable = new \ReflectionMethod(DbalPbsContentStore::class, 'sameNullable');
        self::assertTrue($sameNullable->invoke($store, null, null));
    }

    /** @param array<string, mixed> $options
     *  @return array{DbalPbsContentStore, Connection&MockObject}
     */
    private function store(array $options = []): array
    {
        $options += [
            'existing' => false,
            'owner_conflict' => false,
            'parent_valid' => true,
            'connection_valid' => true,
            'selected_count' => 1,
            'fence_valid' => true,
            'child_valid' => true,
            'commit_matches' => true,
            'datastores' => true,
            'server_valid' => true,
            'begin_insert_failure' => false,
            'run_update' => 1,
            'archive_count' => 1,
            'clock' => self::AT,
            'fence_token' => '1',
            'namespace_id' => self::id('namespace')->binary(),
            'datastore_name' => 'store_a',
        ];
        $lease = $this->lease();
        $endpoint = self::endpoint();
        $database = $this->createMock(Connection::class);
        $database->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($database),
        );
        $database->method('fetchAssociative')->willReturnCallback(
            static function (string $sql) use ($options, $lease, $endpoint): array|false {
                if (str_contains($sql, 'FROM collector_schedule')) {
                    return $options['fence_valid'] ? [
                        'lease_owner' => $lease->ownerId->bytes,
                        'lease_token' => $lease->token->binary(),
                        'lease_fencing_token' => $options['fence_token'],
                        'lease_expires_at' => '2099-01-01 00:00:00.000000',
                    ] : false;
                }
                if (str_contains($sql, 'FROM collector_cycles')) {
                    return [
                        'status' => 'running',
                        'worker_instance_id' => $lease->ownerId->bytes,
                        'worker_kind' => 'collector',
                        'fencing_token' => 1,
                    ];
                }
                if (str_contains($sql, 'FROM inventory_sync_runs')) {
                    return $options['parent_valid'] ? [
                        'status' => 'succeeded', 'applied_at' => self::AT,
                        'endpoint_id' => $endpoint->bytes, 'expected_connection_revision' => 1,
                        'cycle_token' => $lease->token->binary(), 'collector_fencing_token' => 1,
                    ] : false;
                }
                if (str_contains($sql, 'FROM proxmox_connections')) {
                    return $options['connection_valid']
                        ? ['product' => 'pbs', 'enabled' => 1, 'revision' => 1] : false;
                }
                if (str_contains($sql, 'FROM proxmox_installation_bindings')) {
                    return [
                        'product' => 'pbs', 'identity_kind' => 'pbs_legacy_node',
                        'identity_value' => 'pbs-a', 'legacy_endpoint_id' => $endpoint->bytes,
                    ];
                }
                if (str_contains($sql, 'FROM pbs_content_runs')) {
                    if (!$options['child_valid']) {
                        return false;
                    }
                    return [
                        'status' => 'running', 'applied_at' => null,
                        'cycle_token' => $lease->token->binary(), 'collector_fencing_token' => 1,
                        'parent_run_id' => $options['commit_matches'] ? self::id('parent')->binary() : self::id('other')->binary(),
                        'endpoint_id' => $endpoint->bytes, 'expected_connection_revision' => 1,
                    ];
                }
                if (str_contains($sql, 'SELECT id FROM pbs_namespaces')) {
                    return $options['existing'] ? ['id' => $options['namespace_id']] : false;
                }
                if (str_contains($sql, 'SELECT server_id FROM pbs_datastores')) {
                    return $options['server_valid'] ? ['server_id' => self::id('server')->binary()] : false;
                }
                if (str_contains($sql, 'FROM pbs_backup_groups')) {
                    return $options['existing'] ? [
                        'id' => self::id('group')->binary(),
                        'owner_auth_id' => $options['owner_conflict'] ? 'different@pbs' : 'backup@pbs',
                    ] : false;
                }
                if (str_contains($sql, 'SELECT id FROM pbs_snapshots')) {
                    return $options['existing'] ? ['id' => self::id('snapshot')->binary()] : false;
                }
                return false;
            },
        );
        $database->method('fetchOne')->willReturnCallback(
            static fn (string $sql): mixed => str_contains($sql, 'UTC_TIMESTAMP')
                ? $options['clock'] : $options['selected_count'],
        );
        $database->method('fetchAllAssociative')->willReturn(
            $options['datastores'] ? [[
                'id' => self::id('datastore')->binary(), 'datastore_name' => $options['datastore_name'],
            ]] : [],
        );
        $database->method('insert')->willReturnCallback(
            static function (string $table) use ($options): int {
                if ('pbs_content_runs' === $table && $options['begin_insert_failure']) {
                    throw new \RuntimeException('duplicate');
                }
                return 1;
            },
        );
        $runUpdate = $options['run_update'];
        if (!is_int($runUpdate)) {
            throw new \LogicException('The test run update result must be an integer.');
        }
        $database->method('update')->willReturnCallback(
            static fn (string $table): int => 'pbs_content_runs' === $table ? $runUpdate : 1,
        );
        $database->method('executeStatement')->willReturn($options['archive_count']);

        return [new DbalPbsContentStore($database, new PbsContentUnitIds()), $database];
    }

    private function snapshot(
        PbsContentScopeStatus $snapshotStatus = PbsContentScopeStatus::Complete,
        ?string $owner = 'backup@pbs',
    ): PbsContentSnapshot {
        $datastore = new PbsDatastoreId('store_a');
        $root = PbsNamespace::root();
        $nested = new PbsNamespace('tenant');
        return new PbsContentSnapshot(
            [
                new PbsNamespaceObservation($datastore, $root),
                new PbsNamespaceObservation($datastore, $nested),
            ],
            [new PbsSnapshotObservation(
                $datastore, $nested, PbsBackupType::Vm, '100', new DateTimeImmutable('@1'),
                ['archive.blob'], false, null, null, $owner, 100, null,
            )],
            [
                new PbsContentScopeResult(
                    PbsContentScopeType::Namespaces, $datastore, null,
                    PbsContentScopeStatus::Complete, 1,
                ),
                new PbsContentScopeResult(
                    PbsContentScopeType::Snapshots, $datastore, $nested, $snapshotStatus, 1,
                    PbsContentScopeStatus::Complete === $snapshotStatus ? null : 'partial',
                ),
            ],
        );
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId(self::bytes('worker')),
            new CollectorCycleToken(self::bytes('cycle')),
            1,
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
        );
    }

    private function start(InventoryIdentifier $run, InventoryIdentifier $parent): PbsContentRunStart
    {
        return new PbsContentRunStart(
            $run, $parent, self::id('connection'), self::endpoint(),
            InstallationBinding::pbsLegacyNode('pbs-a', self::endpoint()), 1,
            new DateTimeImmutable(self::AT.' UTC'),
        );
    }

    private function commit(
        InventoryIdentifier $run,
        InventoryIdentifier $parent,
        PbsContentSnapshot $snapshot,
    ): PbsContentCommit {
        return new PbsContentCommit(
            $run, $parent, self::id('connection'), self::endpoint(),
            InstallationBinding::pbsLegacyNode('pbs-a', self::endpoint()), 1,
            $snapshot, new DateTimeImmutable(self::AT.' UTC'),
        );
    }

    private static function endpoint(): EndpointId
    {
        return new EndpointId(self::bytes('endpoint'));
    }

    private static function id(string $seed): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes($seed));
    }

    private static function bytes(string $seed): string
    {
        return substr(hash('sha256', $seed, true), 0, 16);
    }
}

final class PbsContentUnitIds implements InventoryIdentifierGenerator
{
    private int $next = 0;

    public function generate(): InventoryIdentifier
    {
        return new InventoryIdentifier(str_pad(pack('N', ++$this->next), 16, "\0", STR_PAD_LEFT));
    }
}
