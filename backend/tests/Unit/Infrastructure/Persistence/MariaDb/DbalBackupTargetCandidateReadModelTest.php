<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Target\ReadModel\BackupTargetCandidateQuery;
use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\MariaDb\DbalBackupTargetCandidateReadModel;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DbalBackupTargetCandidateReadModelTest extends TestCase
{
    public function testLargeNodeFanoutUsesOnePageAndOneBatchNodeQuery(): void
    {
        $storages = [];
        $nodes = [];
        for ($index = 0; $index < 100; ++$index) {
            $storageId = pack('N4', 0, 0, 0, $index + 1);
            $storages[] = [
                'id' => $storageId, 'connection_id' => str_repeat('c', 16),
                'cluster_id' => str_repeat('k', 16), 'storage_name' => sprintf('storage-%03d', $index),
                'storage_type' => 'dir', 'supports_backup' => 1, 'disabled' => 0, 'shared' => 1,
                'node_allowlist_json' => null, 'content_json' => '["backup"]',
                'inventory_state' => 'active', 'last_seen_at' => '2026-07-12 10:00:00.000000',
                'connection_name' => 'PVE', 'connection_product' => 'pve', 'connection_enabled' => 1,
                'cluster_name' => 'cluster', 'cluster_state' => 'active',
                'pbs_server' => null, 'pbs_port' => null, 'pbs_datastore' => null,
                'pbs_namespace' => null, 'pbs_mapping_observed_at' => null,
            ];
            for ($node = 0; $node < 2; ++$node) {
                $nodes[] = [
                    'storage_id' => $storageId, 'id' => pack('N4', 1, $index, 0, $node + 1),
                    'node_name' => sprintf('node-%d', $node), 'api_status' => 'online',
                    'enabled' => 1, 'active' => 1, 'capacity_status' => 'measured',
                    'total_bytes' => '18446744073709551615', 'used_bytes' => '1',
                    'available_bytes' => '18446744073709551614',
                    'observed_at' => '2026-07-12 10:00:00.000000',
                ];
            }
        }
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))->method('fetchAllAssociative')
            ->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'ORDER BY BINARY storage.storage_name')
                ? $storages : (str_contains($sql, 'target_count')
                    ? array_map(static fn (array $storage): array => ['storage_id'=>$storage['id'],'target_count'=>'0','expected_count'=>'0','observed_count'=>'0','vm_backup_authorized'=>null,'datastore_allocate_authorized'=>null,'authorized'=>null,'observed_at'=>null], $storages)
                    : $nodes));

        $clock = $this->createMock(Clock::class);
        $clock->expects(self::once())->method('now')
            ->willReturn(new DateTimeImmutable('2026-07-12T10:05:00.000000Z'));
        $page = (new DbalBackupTargetCandidateReadModel(
            $connection,
            $clock,
            300,
        ))->candidates(
            new BackupTargetCandidateQuery(new PageRequest(100)),
        );

        self::assertCount(100, $page->items);
        self::assertCount(2, $page->items[99]->nodes);
        self::assertFalse($page->items[99]->canEnable());
    }

    public function testEveryMissingEvidenceFamilyFailsClosedWithItsOwnCode(): void
    {
        $storageId = str_repeat('s', 16);
        $storage = [
            'id' => $storageId, 'connection_id' => str_repeat('c', 16),
            'cluster_id' => str_repeat('k', 16), 'storage_name' => 'pbs', 'storage_type' => 'pbs',
            'supports_backup' => 1, 'disabled' => 0, 'shared' => 1, 'node_allowlist_json' => null,
            'content_json' => '["backup"]', 'inventory_state' => 'active', 'last_seen_at' => null,
            'connection_name' => 'PVE', 'connection_product' => 'pve', 'connection_enabled' => 1,
            'cluster_name' => 'cluster', 'cluster_state' => 'active', 'pbs_server' => 'pbs.test',
            'pbs_port' => 8007, 'pbs_datastore' => 'store', 'pbs_namespace' => null,
            'pbs_mapping_observed_at' => null,
        ];
        $node = [
            'storage_id' => $storageId, 'id' => str_repeat('n', 16), 'node_name' => 'node-a',
            'api_status' => 'online', 'enabled' => null, 'active' => null, 'capacity_status' => null,
            'total_bytes' => null, 'used_bytes' => null, 'available_bytes' => null, 'observed_at' => null,
        ];
        $match = [
            'storage_id' => $storageId, 'endpoint_enabled' => 1,
            'pbs_connection_id' => str_repeat('p', 16), 'pbs_connection_enabled' => 1,
            'pbs_server_id' => str_repeat('v', 16), 'pbs_datastore_id' => str_repeat('d', 16),
            'pbs_datastore_state' => 'active', 'allows_backup_writes' => 1,
            'pbs_namespace_id' => str_repeat('m', 16), 'pbs_namespace_state' => 'active',
            'semantics' => null, 'total_bytes' => null, 'used_bytes' => null,
            'available_bytes' => null, 'capacity_observed_at' => null,
        ];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(4))->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($storage, $node, $match): array {
                if (str_contains($sql, 'ORDER BY BINARY storage.storage_name')) {
                    return [$storage];
                }
                if (str_contains($sql, 'target_count')) {
                    return [['storage_id'=>$storage['id'],'target_count'=>'1','expected_count'=>'1','observed_count'=>'0','vm_backup_authorized'=>null,'datastore_allocate_authorized'=>null,'authorized'=>null,'observed_at'=>null]];
                }
                return str_contains($sql, 'pve_node_storage_state') ? [$node] : [$match];
            });

        $candidate = (new DbalBackupTargetCandidateReadModel(
            $connection,
            new FrozenClock(new DateTimeImmutable('2026-07-12T10:05:00.000000Z')),
            300,
        ))->candidates(new BackupTargetCandidateQuery(new PageRequest(1)))->items[0];

        self::assertSame(
            ['storage_inventory_evidence_missing', 'executor_evidence_missing', 'no_usable_node'],
            array_column($candidate->blockers, 'value'),
        );
        self::assertSame(
            ['node_state_missing', 'node_state_evidence_missing', 'capacity_evidence_missing'],
            array_column($candidate->nodes[0]->blockers, 'value'),
        );
        self::assertNotNull($candidate->pbs);
        self::assertSame(
            ['pbs_mapping_evidence_missing', 'pbs_capacity_missing', 'pbs_capacity_evidence_missing'],
            array_column($candidate->pbs->blockers, 'value'),
        );
    }
}
