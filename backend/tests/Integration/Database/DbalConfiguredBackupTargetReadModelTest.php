<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Target\ReadModel\ConfiguredBackupTargetQuery;
use App\Infrastructure\Persistence\MariaDb\DbalConfiguredBackupTargetReadModel;

final class DbalConfiguredBackupTargetReadModelTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-12 10:00:00.000000';

    public function testConfiguredTargetsUseRealKeysetFiltersAllowedNodesAndLosslessBytes(): void
    {
        $this->seed();
        $model = new DbalConfiguredBackupTargetReadModel($this->connection());

        $first = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(1), null, false));
        self::assertCount(1, $first->items);
        self::assertNotNull($first->nextCursor);
        self::assertSame('Alpha', $first->items[0]->displayName);
        self::assertSame('18446744073709551615', $first->items[0]->minimumFreeBytes?->value);
        self::assertSame(['node-a'], array_column($first->items[0]->allowedNodes, 'name'));
        self::assertSame(
            ['configuration_incomplete', 'executor_evidence_missing'],
            $first->items[0]->toArray()['blockers'],
        );

        $second = $model->targets(new ConfiguredBackupTargetQuery(
            new PageRequest(1, $first->nextCursor),
            null,
            false,
        ));
        self::assertCount(1, $second->items);
        self::assertNull($second->nextCursor);
        self::assertSame('Zulu 50%_copy', $second->items[0]->displayName);
        self::assertSame([], $second->items[0]->allowedNodes);

        $literalSearch = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), '%_', false));
        self::assertCount(1, $literalSearch->items);
        self::assertSame('Zulu 50%_copy', $literalSearch->items[0]->displayName);
        self::assertSame([], $model->targets(
            new ConfiguredBackupTargetQuery(new PageRequest(10), null, true),
        )->items);
    }

    private function seed(): void
    {
        $connection = str_repeat("\x01", 16);
        $cluster = str_repeat("\x02", 16);
        $storage = str_repeat("\x03", 16);
        $node = str_repeat("\x04", 16);
        $run = str_repeat("\x05", 16);
        $first = str_repeat("\x06", 16);
        $second = str_repeat("\x07", 16);

        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->connection()->insert('proxmox_connections', [
                'id' => $connection, 'display_name' => 'PVE', 'product' => 'pve', 'enabled' => 1,
                'revision' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_clusters', [
                'id' => $cluster, 'connection_id' => $connection, 'external_name' => 'cluster-a',
                'topology' => 'clustered', 'inventory_state' => 'active',
                'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_nodes', [
                'id' => $node, 'connection_id' => $connection, 'cluster_id' => $cluster,
                'node_name' => 'node-a', 'api_status' => 'online', 'inventory_state' => 'active',
                'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_storages', [
                'id' => $storage, 'connection_id' => $connection, 'cluster_id' => $cluster,
                'storage_name' => 'backup', 'storage_type' => 'dir', 'supports_backup' => 1,
                'shared' => 1, 'disabled' => 0, 'content_json' => '["backup"]',
                'inventory_state' => 'active', 'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
            ]);
            foreach ([[$first, 'Alpha', '18446744073709551615'], [$second, 'Zulu 50%_copy', null]] as [$id, $name, $minimum]) {
                $this->connection()->insert('backup_targets', [
                    'id' => $id, 'connection_id' => $connection, 'cluster_id' => $cluster,
                    'storage_id' => $storage, 'display_name' => $name, 'status' => 'disabled',
                    'revision' => 1, 'minimum_free_bytes' => $minimum,
                    'created_at' => self::NOW, 'updated_at' => self::NOW, 'disabled_at' => self::NOW,
                ]);
            }
            $this->connection()->insert('backup_target_allowed_nodes', [
                'target_id' => $first, 'connection_id' => $connection, 'cluster_id' => $cluster,
                'node_id' => $node, 'created_at' => self::NOW,
            ]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
