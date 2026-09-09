<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\CollectorScopeQuery;
use App\Application\Inventory\ReadModel\InventoryResourceKind;
use App\Application\Inventory\ReadModel\InventoryResourceQuery;
use App\Application\Inventory\ReadModel\InventoryState;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Infrastructure\Persistence\MariaDb\DbalInventoryReadModel;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalInventoryReadModelUnitTest extends TestCase
{
    private const string AT = '2026-07-12 10:00:00.000000';
    private const string UUID = '00112233-4455-6677-8899-aabbccddeeff';

    public function testOverviewAndCollectorStatusMapDatabaseRows(): void
    {
        $database = $this->connection();
        $count = 0;
        $database->method('fetchOne')->willReturnCallback(static function (string $sql) use (&$count): mixed {
            if (str_contains($sql, 'COUNT(id)')) {
                return 1 === ++$count % 2 ? '2' : 3;
            }
            if (str_contains($sql, 'MAX(observed_at)')) {
                return self::AT;
            }
            return new DateTimeImmutable('2026-07-12T10:00:00Z');
        });
        $database->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            [
                'interval_seconds' => '120',
                'next_scan_at' => self::AT,
                'last_cycle_started_at' => null,
                'last_cycle_finished_at' => self::AT,
                'lease_expires_at' => '2099-01-01 00:00:00.000000',
            ],
            [
                'status' => 'ready',
                'started_at' => self::AT,
                'heartbeat_at' => self::AT,
                'expires_at' => '2099-01-01 00:00:00.000000',
                'current_activity' => null,
                'next_action_at' => self::AT,
                'build_version' => 'test',
            ],
        );
        $model = new DbalInventoryReadModel($database);

        $overview = $model->overview()->toArray();
        self::assertCount(12, $overview['counts']);
        self::assertSame('2026-07-12T10:00:00.000000Z', $overview['latestInventoryAt']);
        $status = $model->status()->toArray();
        $schedule = $status['schedule'];
        $heartbeat = $status['heartbeat'];
        self::assertIsArray($schedule);
        self::assertIsArray($heartbeat);
        self::assertTrue($schedule['configured']);
        self::assertTrue($schedule['leaseActive']);
        self::assertTrue($heartbeat['fresh']);

        $emptyDatabase = $this->connection();
        $emptyDatabase->method('fetchOne')->willReturnMap([
            ['SELECT UTC_TIMESTAMP(6)', [], [], self::AT],
        ]);
        $emptyDatabase->method('fetchAssociative')->willReturn(false);
        $emptyStatus = (new DbalInventoryReadModel($emptyDatabase))->status()->toArray();
        self::assertSame(['configured' => false], $emptyStatus['schedule']);
        self::assertNull($emptyStatus['heartbeat']);

        $nullLatest = $this->connection();
        $nullLatest->method('fetchOne')->willReturnCallback(static function (string $sql): mixed {
            if (str_contains($sql, 'COUNT(id)')) {
                return 0;
            }
            return str_contains($sql, 'MAX(observed_at)') ? null : self::AT;
        });
        self::assertNull((new DbalInventoryReadModel($nullLatest))->overview()->latestInventoryAt);
    }

    public function testEveryResourceKindMapsItsSafePublicShapeAndFilters(): void
    {
        foreach (InventoryResourceKind::cases() as $kind) {
            $row = $this->resourceRow($kind);
            $database = $this->connection();
            $database->expects(self::once())->method('fetchAllAssociative')
                ->willReturn([$row, [...$row, 'id' => self::bytes($kind->value.'-second')]]);
            $query = new InventoryResourceQuery(
                $kind,
                new PageRequest(1),
                new ReadModelIdentifier(self::UUID),
                $kind->permitsParentFilter() ? new ReadModelIdentifier(self::UUID) : null,
                InventoryState::Active,
                InventoryResourceKind::PveGuest === $kind ? 'qemu' : null,
            );

            $page = (new DbalInventoryReadModel($database))->resources($query);

            self::assertCount(1, $page->items);
            self::assertInstanceOf(\App\Application\Inventory\ReadModel\InventoryResource::class, $page->items[0]);
            self::assertSame($kind, $page->items[0]->kind);
            self::assertNotNull($page->nextCursor);
        }

        $database = $this->connection();
        $database->expects(self::never())->method('fetchAllAssociative');
        $archivedServers = (new DbalInventoryReadModel($database))->resources(new InventoryResourceQuery(
            InventoryResourceKind::PbsServer,
            new PageRequest(),
            inventoryState: InventoryState::Archived,
        ));
        self::assertSame([], $archivedServers->items);

        $cursorDatabase = $this->connection();
        $cursorDatabase->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, ':cursor_display')),
                self::callback(static fn (array $params): bool => 'node-a' === ($params['cursor_display'] ?? null)),
                self::anything(),
            )->willReturn([$this->resourceRow(InventoryResourceKind::PveNode)]);
        $base = new InventoryResourceQuery(InventoryResourceKind::PveNode, new PageRequest());
        $cursor = PageCursor::resource($base->cursorContext(), 'node-a', self::UUID);
        $page = (new DbalInventoryReadModel($cursorDatabase))->resources(new InventoryResourceQuery(
            InventoryResourceKind::PveNode,
            new PageRequest(10, $cursor),
        ));
        self::assertNull($page->nextCursor);
    }

    public function testRunAndCombinedScopeKeysetPagesAreStable(): void
    {
        $runDatabase = $this->connection();
        $runDatabase->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [$this->runRow('a'), $this->runRow('b')],
            [$this->runRow('b')],
        );
        $model = new DbalInventoryReadModel($runDatabase);
        $first = $model->runs(new PageRequest(1));
        self::assertNotNull($first->nextCursor);
        self::assertCount(1, $model->runs(new PageRequest(1, $first->nextCursor))->items);

        $scopeDatabase = $this->connection();
        $scopeDatabase->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [[...$this->scopeRow('pbs_namespaces', 'a'), 'error_code' => 'access_denied'], $this->scopeRow('pbs_snapshots', 'b')],
            [$this->scopeRow('pbs_snapshots', 'b')],
        );
        $scopeModel = new DbalInventoryReadModel($scopeDatabase);
        $query = new CollectorScopeQuery(new ReadModelIdentifier(self::UUID), new PageRequest(1));
        $scopes = $scopeModel->scopes($query);
        self::assertNotNull($scopes->nextCursor);
        self::assertCount(1, $scopeModel->scopes(new CollectorScopeQuery(
            new ReadModelIdentifier(self::UUID),
            new PageRequest(1, $scopes->nextCursor),
        ))->items);
    }

    /** @return iterable<string, array{InventoryResourceKind, string, mixed}> */
    public static function invalidResourceRows(): iterable
    {
        yield 'identifier' => [InventoryResourceKind::PveNode, 'id', 'short'];
        yield 'timestamp type' => [InventoryResourceKind::PveNode, 'first_seen_at', 12];
        yield 'timestamp value' => [InventoryResourceKind::PveNode, 'first_seen_at', 'not-a-date'];
        yield 'text' => [InventoryResourceKind::PveNode, 'connection_name', 12];
        yield 'integer' => [InventoryResourceKind::PbsNamespace, 'namespace_depth', new \stdClass()];
        yield 'boolean' => [InventoryResourceKind::PbsDatastore, 'allows_backup_writes', 2];
        yield 'json type' => [InventoryResourceKind::PveStorage, 'content_json', null];
        yield 'json value' => [InventoryResourceKind::PveStorage, 'content_json', 'false'];
        yield 'json item' => [InventoryResourceKind::PveStorage, 'content_json', '[1]'];
    }

    #[DataProvider('invalidResourceRows')]
    public function testInvalidResourceRowsFailClosed(
        InventoryResourceKind $kind,
        string $column,
        mixed $value,
    ): void {
        $database = $this->connection();
        $database->method('fetchAllAssociative')->willReturn([[
            ...$this->resourceRow($kind),
            $column => $value,
        ]]);

        $this->expectException(RuntimeException::class);
        (new DbalInventoryReadModel($database))->resources(new InventoryResourceQuery($kind, new PageRequest()));
    }

    public function testInvalidRunAndScopeRowsFailClosed(): void
    {
        $invalidBoolean = $this->connection();
        $invalidBoolean->method('fetchAllAssociative')->willReturn([[
            ...$this->runRow('bad'),
            'authoritative' => 2,
        ]]);
        try {
            (new DbalInventoryReadModel($invalidBoolean))->runs(new PageRequest());
            self::fail('Invalid run boolean accepted.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $invalidError = $this->connection();
        $invalidError->method('fetchAllAssociative')->willReturn([[
            ...$this->scopeRow('pbs_snapshots', 'root'),
            'error_code' => 'Bad-Code',
        ]]);
        $this->expectException(RuntimeException::class);
        (new DbalInventoryReadModel($invalidError))->scopes(new CollectorScopeQuery(
            new ReadModelIdentifier(self::UUID),
            new PageRequest(),
        ));
    }

    public function testDatabaseTimestampGuardRejectsAnImpossibleInternalCursorValue(): void
    {
        $method = new \ReflectionMethod(DbalInventoryReadModel::class, 'databaseDate');
        $this->expectException(RuntimeException::class);
        $method->invoke(new DbalInventoryReadModel($this->connection()), '2026-02-30T10:00:00.000000Z');
    }

    /** @return Connection&MockObject */
    private function connection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    /** @return array<string, mixed> */
    private function resourceRow(InventoryResourceKind $kind): array
    {
        $row = [
            'id' => self::bytes($kind->value),
            'connection_id' => self::bytes('connection'),
            'connection_name' => 'Connection',
            'display_name' => 'resource-a',
            'inventory_state' => 'active',
            'first_seen_at' => self::AT,
            'last_seen_at' => self::AT,
            'archived_at' => null,
            'state_observed_at' => self::AT,
        ];

        return match ($kind) {
            InventoryResourceKind::PveCluster => [...$row, 'topology' => 'clustered'],
            InventoryResourceKind::PveNode => [...$row, 'cluster_id' => self::bytes('parent'), 'node_name' => 'node-a', 'api_status' => 'online'],
            InventoryResourceKind::PveGuest => [...$row, 'cluster_id' => self::bytes('parent'), 'guest_type' => 'qemu', 'vmid' => '100', 'name' => null, 'is_template' => null, 'node_id' => null, 'node_name' => null],
            InventoryResourceKind::PveStorage => [...$row, 'cluster_id' => self::bytes('parent'), 'storage_type' => 'pbs', 'supports_backup' => 1, 'shared' => '1', 'disabled' => 0, 'content_json' => '["backup"]', 'pbs_server' => null, 'pbs_port' => '8007', 'pbs_datastore' => 'store', 'pbs_namespace' => null],
            InventoryResourceKind::PbsServer => [...$row, 'node_name' => 'pbs-a', 'version_text' => '4.2', 'release_text' => '1', 'repo_id' => 'repo', 'uptime_seconds' => null, 'memory_total_bytes' => '100', 'memory_used_bytes' => 10, 'root_total_bytes' => null, 'root_used_bytes' => null, 'root_available_bytes' => null],
            InventoryResourceKind::PbsDatastore => [...$row, 'server_id' => self::bytes('parent'), 'datastore_name' => 'store', 'backend_type' => 'filesystem', 'mount_status' => 'mounted', 'maintenance_mode' => null, 'allows_backup_writes' => 1, 'semantics' => null, 'total_bytes' => null, 'used_bytes' => 1, 'available_bytes' => '2'],
            InventoryResourceKind::PbsNamespace => [...$row, 'datastore_id' => self::bytes('parent'), 'parent_namespace_id' => null, 'namespace_path' => '', 'namespace_depth' => 0],
            InventoryResourceKind::PbsBackupGroup => [...$row, 'namespace_id' => self::bytes('parent'), 'backup_type' => 'vm', 'backup_id' => '100'],
            InventoryResourceKind::PbsSnapshot => [...$row, 'group_id' => self::bytes('parent'), 'backup_time' => self::AT, 'protected' => 1, 'size_bytes' => null, 'verification_state' => null],
        };
    }

    /** @return array<string, mixed> */
    private function runRow(string $seed): array
    {
        return [
            'id' => self::bytes('run-'.$seed), 'connection_id' => self::bytes('connection'),
            'display_name' => 'Connection', 'product' => 'pbs', 'status' => 'succeeded',
            'authoritative' => 1, 'started_at' => self::AT, 'finished_at' => null,
            'applied_at' => self::AT, 'nodes_seen' => '1', 'guests_seen' => 2,
            'storages_seen' => 3, 'error_code' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function scopeRow(string $type, string $key): array
    {
        return [
            'sync_run_id' => self::bytes('run'), 'scope_type' => $type, 'scope_key' => $key,
            'status' => 'complete', 'observed_at' => self::AT, 'error_code' => null,
        ];
    }

    private static function bytes(string $seed): string
    {
        return substr(hash('sha256', $seed, true), 0, 16);
    }
}
