<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Target\ReadModel\BackupTargetCandidateQuery;
use App\Infrastructure\Persistence\MariaDb\DbalBackupTargetCandidateReadModel;
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
        $connection->expects(self::exactly(2))->method('fetchAllAssociative')
            ->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'ORDER BY BINARY storage.storage_name')
                ? $storages : $nodes);

        $page = (new DbalBackupTargetCandidateReadModel($connection))->candidates(
            new BackupTargetCandidateQuery(new PageRequest(100)),
        );

        self::assertCount(100, $page->items);
        self::assertCount(2, $page->items[99]->nodes);
        self::assertFalse($page->items[99]->canEnable());
    }
}
