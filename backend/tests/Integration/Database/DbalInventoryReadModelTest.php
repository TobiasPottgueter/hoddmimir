<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\ReadModel\CollectorScopeQuery;
use App\Application\Inventory\ReadModel\InventoryResourceKind;
use App\Application\Inventory\ReadModel\InventoryResourceQuery;
use App\Application\Inventory\ReadModel\InventoryState;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Infrastructure\Persistence\MariaDb\DbalInventoryReadModel;

final class DbalInventoryReadModelTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-12 10:00:00.000000';
    private const string LATER = '2026-07-12 10:01:00.000000';

    public function testItReturnsBoundedPvePbsAndCollectorReadModelsWithoutSecrets(): void
    {
        $ids = $this->seedInventory();
        $model = new DbalInventoryReadModel($this->connection());

        $overview = $model->overview()->toArray();
        self::assertGreaterThanOrEqual(1, $overview['counts']['pveConnections']);
        self::assertGreaterThanOrEqual(1, $overview['counts']['pbsConnections']);
        self::assertSame(3, $overview['counts']['pbsNamespaces']);
        self::assertSame(1, $overview['counts']['pbsBackupGroups']);
        self::assertSame(1, $overview['counts']['pbsSnapshots']);
        self::assertSame('2026-07-12T10:01:00.000000Z', $overview['latestInventoryAt']);

        $expectedKinds = [
            InventoryResourceKind::PveCluster,
            InventoryResourceKind::PveNode,
            InventoryResourceKind::PveGuest,
            InventoryResourceKind::PveStorage,
            InventoryResourceKind::PbsServer,
            InventoryResourceKind::PbsDatastore,
            InventoryResourceKind::PbsNamespace,
            InventoryResourceKind::PbsBackupGroup,
            InventoryResourceKind::PbsSnapshot,
        ];
        foreach ($expectedKinds as $kind) {
            $page = $model->resources(new InventoryResourceQuery(
                $kind,
                new PageRequest(10),
                new ReadModelIdentifier(in_array($kind, [
                    InventoryResourceKind::PbsServer,
                    InventoryResourceKind::PbsDatastore,
                    InventoryResourceKind::PbsNamespace,
                    InventoryResourceKind::PbsBackupGroup,
                    InventoryResourceKind::PbsSnapshot,
                ], true) ? $ids['pbsConnection'] : $ids['pveConnection']),
                match ($kind) {
                    InventoryResourceKind::PveNode,
                    InventoryResourceKind::PveGuest,
                    InventoryResourceKind::PveStorage => new ReadModelIdentifier($ids['cluster']),
                    InventoryResourceKind::PbsDatastore => new ReadModelIdentifier($ids['server']),
                    InventoryResourceKind::PbsBackupGroup => new ReadModelIdentifier($ids['namespace']),
                    InventoryResourceKind::PbsSnapshot => new ReadModelIdentifier($ids['group']),
                    default => null,
                },
                InventoryState::Active,
                InventoryResourceKind::PveGuest === $kind ? 'qemu' : null,
            ))->toArray();
            self::assertNotEmpty($page['items'], $kind->value);
            self::assertSame($kind->value, $page['items'][0]['kind']);
            self::assertArrayNotHasKey('host', $page['items'][0]);
            self::assertArrayNotHasKey('secret', $page['items'][0]);
            self::assertStringNotContainsString('owner_auth_id', json_encode($page, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('encryption_fingerprint', json_encode($page, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('verification_upid', json_encode($page, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('files_json', json_encode($page, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('comment', json_encode($page, JSON_THROW_ON_ERROR));
            self::assertIsString($page['items'][0]['lastSeenAt']);
            self::assertStringEndsWith('Z', $page['items'][0]['lastSeenAt']);
        }

        $nodePage = $model->resources(new InventoryResourceQuery(
            InventoryResourceKind::PveNode,
            new PageRequest(1),
            new ReadModelIdentifier($ids['pveConnection']),
            new ReadModelIdentifier($ids['cluster']),
            InventoryState::Active,
        ));
        self::assertCount(1, $nodePage->items);
        self::assertNotNull($nodePage->nextCursor);
        self::assertCount(1, $model->resources(new InventoryResourceQuery(
            InventoryResourceKind::PveNode,
            new PageRequest(1, $nodePage->nextCursor),
            new ReadModelIdentifier($ids['pveConnection']),
            new ReadModelIdentifier($ids['cluster']),
            InventoryState::Active,
        ))->items);
        self::assertSame([], $model->resources(new InventoryResourceQuery(
            InventoryResourceKind::PbsServer,
            new PageRequest(),
            inventoryState: InventoryState::Archived,
        ))->items);

        $namespaces = $model->resources(new InventoryResourceQuery(
            InventoryResourceKind::PbsNamespace,
            new PageRequest(10),
            new ReadModelIdentifier($ids['pbsConnection']),
        ))->toArray();
        $orphan = array_values(array_filter(
            $namespaces['items'],
            static fn (array $item): bool => 'tenant/orphan' === $item['displayName'],
        ));
        self::assertCount(1, $orphan);
        self::assertNull($orphan[0]['parentId']);
        $datastoreChildren = $model->resources(new InventoryResourceQuery(
            InventoryResourceKind::PbsNamespace,
            new PageRequest(10),
            new ReadModelIdentifier($ids['pbsConnection']),
            new ReadModelIdentifier($ids['datastore']),
        ))->toArray();
        self::assertSame(['@root'], array_column($datastoreChildren['items'], 'displayName'));

        $status = $model->status()->toArray();
        self::assertIsArray($status['schedule']);
        self::assertTrue($status['schedule']['configured']);
        self::assertIsBool($status['schedule']['leaseActive']);

        $runPage = $model->runs(new PageRequest(1));
        self::assertNotEmpty($runPage->items);
        self::assertNotNull($runPage->nextCursor);
        $nextRunPage = $model->runs(new PageRequest(1, $runPage->nextCursor));
        self::assertCount(1, $nextRunPage->items);
        $firstRun = $runPage->items[0]->toArray();
        $secondRun = $nextRunPage->items[0]->toArray();
        self::assertNotSame($firstRun['id'], $secondRun['id']);
        self::assertArrayNotHasKey('errorSummary', $firstRun);
        $scopes = $model->scopes(new CollectorScopeQuery(
            new ReadModelIdentifier($ids['pveRun']),
            new PageRequest(1),
        ))->toArray();
        self::assertSame('pve_guests', $scopes['items'][0]['scopeType']);
        self::assertSame('2026-07-12T10:01:00.000000Z', $scopes['items'][0]['observedAt']);
        self::assertTrue($scopes['page']['hasMore']);
        self::assertIsString($scopes['page']['nextCursor']);
        $monitoringScopes = $model->scopes(new CollectorScopeQuery(
            new ReadModelIdentifier($ids['pveRun']),
            new PageRequest(1, \App\Application\Inventory\ReadModel\PageCursor::decode($scopes['page']['nextCursor'])),
        ))->toArray();
        self::assertSame('pve_tasks_active', $monitoringScopes['items'][0]['scopeType']);
        self::assertSame('access_denied', $monitoringScopes['items'][0]['errorCode']);

        $contentScopes = $model->scopes(new CollectorScopeQuery(
            new ReadModelIdentifier($ids['pbsRun']),
            new PageRequest(10),
        ))->toArray();
        self::assertSame(['pbs_namespaces', 'pbs_snapshots'], array_column($contentScopes['items'], 'scopeType'));
        self::assertSame('access_denied', $contentScopes['items'][1]['errorCode']);
    }

    /** @return array<string, string> */
    private function seedInventory(): array
    {
        $pveConnection = random_bytes(16);
        $pbsConnection = random_bytes(16);
        $pveRun = random_bytes(16);
        $pbsRun = random_bytes(16);
        $cluster = random_bytes(16);
        $nodeA = random_bytes(16);
        $nodeB = random_bytes(16);
        $guest = random_bytes(16);
        $storage = random_bytes(16);
        $server = random_bytes(16);
        $datastore = random_bytes(16);
        $contentRun = random_bytes(16);
        $rootNamespace = random_bytes(16);
        $namespace = random_bytes(16);
        $orphanNamespace = random_bytes(16);
        $group = random_bytes(16);
        $snapshot = random_bytes(16);
        $monitoringRun = random_bytes(16);

        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->connection()->executeStatement(<<<'SQL'
                INSERT INTO collector_schedule (
                    schedule_name, grid_started_at, interval_seconds, next_scan_at,
                    lease_fencing_token, last_cycle_started_at, last_cycle_finished_at, updated_at
                ) VALUES (
                    'inventory', :now, 120, :later, 0, :now, :later, :later
                ) ON DUPLICATE KEY UPDATE
                    interval_seconds = VALUES(interval_seconds),
                    last_cycle_started_at = VALUES(last_cycle_started_at),
                    last_cycle_finished_at = VALUES(last_cycle_finished_at), updated_at = VALUES(updated_at)
                SQL, ['now' => self::NOW, 'later' => self::LATER]);
            foreach ([[$pveConnection, 'PVE API', 'pve'], [$pbsConnection, 'PBS API', 'pbs']] as [$id, $name, $product]) {
                $this->connection()->insert('proxmox_connections', [
                    'id' => $id, 'display_name' => $name.' '.bin2hex(random_bytes(3)), 'product' => $product,
                    'enabled' => 1, 'revision' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW,
                ]);
            }
            foreach ([[$pveRun, $pveConnection], [$pbsRun, $pbsConnection]] as [$run, $connection]) {
                $this->connection()->insert('inventory_sync_runs', [
                    'id' => $run, 'cycle_token' => random_bytes(16), 'collector_fencing_token' => 1,
                    'connection_id' => $connection, 'expected_connection_revision' => 1,
                    'status' => 'succeeded', 'authoritative' => 1, 'started_at' => self::NOW,
                    'heartbeat_at' => self::LATER, 'finished_at' => self::LATER, 'applied_at' => self::LATER,
                    'nodes_seen' => 2, 'guests_seen' => 1, 'storages_seen' => 1,
                ]);
            }
            $this->connection()->insert('inventory_sync_scope_results', [
                'connection_id' => $pveConnection, 'sync_run_id' => $pveRun, 'scope_type' => 'pve_guests',
                'scope_key' => '@installation', 'status' => 'complete', 'observed_at' => self::LATER,
            ]);
            $this->connection()->insert('pve_clusters', [
                'id' => $cluster, 'connection_id' => $pveConnection, 'external_name' => 'cluster-a',
                'topology' => 'clustered', 'inventory_state' => 'active', 'first_seen_run_id' => $pveRun,
                'last_seen_run_id' => $pveRun, 'first_seen_at' => self::NOW, 'last_seen_at' => self::LATER,
            ]);
            foreach ([[$nodeA, 'node-a'], [$nodeB, 'node-b']] as [$node, $name]) {
                $this->connection()->insert('pve_nodes', [
                    'id' => $node, 'connection_id' => $pveConnection, 'cluster_id' => $cluster,
                    'node_name' => $name, 'api_status' => 'online', 'inventory_state' => 'active',
                    'first_seen_run_id' => $pveRun, 'last_seen_run_id' => $pveRun,
                    'first_seen_at' => self::NOW, 'last_seen_at' => self::LATER,
                ]);
            }
            $this->connection()->insert('guests', [
                'id' => $guest, 'connection_id' => $pveConnection, 'cluster_id' => $cluster,
                'guest_type' => 'qemu', 'vmid' => 101, 'name' => 'vm-101', 'is_template' => 0,
                'inventory_state' => 'active', 'first_seen_run_id' => $pveRun, 'last_seen_run_id' => $pveRun,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::LATER,
            ]);
            $this->connection()->insert('guest_placements', [
                'guest_id' => $guest, 'connection_id' => $pveConnection, 'cluster_id' => $cluster,
                'node_id' => $nodeA, 'observed_at' => self::LATER, 'sync_run_id' => $pveRun,
            ]);
            $this->connection()->insert('pve_storages', [
                'id' => $storage, 'connection_id' => $pveConnection, 'cluster_id' => $cluster,
                'storage_name' => 'backup-pbs', 'storage_type' => 'pbs', 'supports_backup' => 1,
                'shared' => 1, 'inventory_state' => 'active', 'first_seen_run_id' => $pveRun,
                'last_seen_run_id' => $pveRun, 'disabled' => 0, 'content_json' => '["backup"]',
                'first_seen_at' => self::NOW, 'last_seen_at' => self::LATER,
            ]);
            $this->connection()->insert('pve_storage_pbs_mappings', [
                'storage_id' => $storage, 'connection_id' => $pveConnection, 'cluster_id' => $cluster,
                'server' => 'pbs.example.test', 'port' => 8007, 'datastore' => 'primary',
                'observed_at' => self::LATER, 'sync_run_id' => $pveRun,
            ]);
            $this->connection()->insert('pbs_servers', [
                'id' => $server, 'connection_id' => $pbsConnection, 'node_name' => 'pbs-a',
                'version_major' => 4, 'version_minor' => 2, 'version_patch' => 0,
                'version_text' => '4.2.0', 'release_text' => '1', 'repo_id' => 'repo',
                'first_seen_run_id' => $pbsRun, 'last_seen_run_id' => $pbsRun,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::LATER,
            ]);
            $this->connection()->insert('pbs_server_status', [
                'server_id' => $server, 'connection_id' => $pbsConnection, 'uptime_seconds' => 10,
                'memory_total_bytes' => 1000, 'memory_used_bytes' => 100,
                'root_total_bytes' => 2000, 'root_used_bytes' => 200, 'root_available_bytes' => 1800,
                'observed_at' => self::LATER, 'sync_run_id' => $pbsRun,
            ]);
            $this->connection()->insert('pbs_datastores', [
                'id' => $datastore, 'connection_id' => $pbsConnection, 'server_id' => $server,
                'datastore_name' => 'primary', 'backend_type' => 'filesystem', 'mount_status' => 'mounted',
                'allows_backup_writes' => 1, 'inventory_state' => 'active', 'first_seen_run_id' => $pbsRun,
                'last_seen_run_id' => $pbsRun, 'first_seen_at' => self::NOW, 'last_seen_at' => self::LATER,
            ]);
            $this->connection()->insert('pbs_datastore_capacity_state', [
                'datastore_id' => $datastore, 'connection_id' => $pbsConnection, 'server_id' => $server,
                'backend_type' => 'filesystem', 'semantics' => 'datastore_filesystem',
                'total_bytes' => 3000, 'used_bytes' => 300, 'available_bytes' => 2700,
                'observed_at' => self::LATER, 'sync_run_id' => $pbsRun,
            ]);
            $this->connection()->insert('pbs_content_runs', [
                'id' => $contentRun, 'parent_run_id' => $pbsRun, 'cycle_token' => random_bytes(16),
                'collector_fencing_token' => 1, 'connection_id' => $pbsConnection,
                'endpoint_id' => random_bytes(16), 'expected_connection_revision' => 1,
                'status' => 'partial', 'namespaces_seen' => 3, 'snapshots_seen' => 1,
                'objects_created' => 5, 'objects_updated' => 0, 'objects_archived' => 0,
                'started_at' => self::NOW, 'heartbeat_at' => self::LATER,
                'finished_at' => self::LATER, 'applied_at' => self::LATER,
            ]);
            foreach ([
                [random_bytes(16), 'pbs_namespaces', '@namespaces', 'complete', null],
                [random_bytes(16), 'pbs_snapshots', '', 'partial', 'access_denied'],
            ] as [$id, $scopeType, $namespacePath, $status, $errorCode]) {
                $this->connection()->insert('pbs_content_scope_results', [
                    'id' => $id, 'connection_id' => $pbsConnection, 'content_run_id' => $contentRun,
                    'datastore_name' => 'primary', 'scope_type' => $scopeType,
                    'namespace_path' => $namespacePath, 'status' => $status, 'rows_read' => 1,
                    'error_code' => $errorCode,
                ]);
            }
            foreach ([
                [$rootNamespace, '', 0, null],
                [$namespace, 'tenant', 1, $rootNamespace],
                [$orphanNamespace, 'tenant/orphan', 2, null],
            ] as [$id, $path, $depth, $parent]) {
                $this->connection()->insert('pbs_namespaces', [
                    'id' => $id, 'connection_id' => $pbsConnection, 'server_id' => $server,
                    'datastore_id' => $datastore, 'namespace_path' => $path,
                    'namespace_depth' => $depth, 'parent_namespace_id' => $parent,
                    'inventory_state' => 'active', 'first_seen_run_id' => $contentRun,
                    'last_seen_run_id' => $contentRun, 'first_seen_at' => self::NOW,
                    'last_seen_at' => self::LATER,
                ]);
            }
            $this->connection()->insert('pbs_backup_groups', [
                'id' => $group, 'connection_id' => $pbsConnection, 'namespace_id' => $namespace,
                'backup_type' => 'vm', 'backup_id' => '101', 'owner_auth_id' => 'hidden@pbs',
                'inventory_state' => 'active', 'first_seen_run_id' => $contentRun,
                'last_seen_run_id' => $contentRun, 'first_seen_at' => self::NOW,
                'last_seen_at' => self::LATER,
            ]);
            $this->connection()->insert('pbs_snapshots', [
                'id' => $snapshot, 'connection_id' => $pbsConnection, 'group_id' => $group,
                'backup_time' => self::NOW, 'protected' => 1, 'size_bytes' => 1234,
                'comment' => 'hidden comment', 'encryption_fingerprint' => 'hidden-fingerprint',
                'verification_state' => 'ok', 'verification_upid' => 'hidden-upid',
                'files_json' => '[{"filename":"hidden.pxar"}]', 'inventory_state' => 'active',
                'first_seen_run_id' => $contentRun, 'last_seen_run_id' => $contentRun,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::LATER,
            ]);
            $this->connection()->insert('proxmox_monitoring_runs', [
                'id' => $monitoringRun, 'connection_id' => $pveConnection,
                'parent_sync_run_id' => $pveRun, 'product' => 'pve',
                'binding_kind' => 'pve_cluster', 'binding_value' => 'cluster-a',
                'monitoring_kind' => 'observed_tasks', 'expected_connection_revision' => 1,
                'endpoint_id' => random_bytes(16), 'cycle_token' => random_bytes(16),
                'collector_fencing_token' => 1, 'status' => 'succeeded',
                'started_at' => self::NOW, 'heartbeat_at' => self::LATER,
                'finished_at' => self::LATER, 'applied_at' => self::LATER,
                'scopes_seen' => 1, 'pages_read' => 0, 'rows_read' => 0, 'items_seen' => 0,
                'objects_created' => 0, 'objects_updated' => 0, 'conflicts_seen' => 0,
            ]);
            $this->connection()->insert('proxmox_monitoring_scope_results', [
                'id' => random_bytes(16), 'connection_id' => $pveConnection,
                'monitoring_run_id' => $monitoringRun, 'scope_type' => 'pve_tasks_active',
                'scope_key' => '@installation', 'source_kind' => 'active', 'filter_value' => 'vzdump',
                'status' => 'partial', 'pages_read' => 0, 'rows_read' => 0, 'items_seen' => 0,
                'truncated' => 0, 'history_gap' => 0, 'error_code' => 'access_denied',
                'observed_at' => self::LATER,
            ]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return [
            'pveConnection' => $this->uuid($pveConnection), 'pbsConnection' => $this->uuid($pbsConnection),
            'pveRun' => $this->uuid($pveRun), 'pbsRun' => $this->uuid($pbsRun),
            'cluster' => $this->uuid($cluster), 'server' => $this->uuid($server),
            'datastore' => $this->uuid($datastore), 'namespace' => $this->uuid($namespace),
            'group' => $this->uuid($group),
        ];
    }

    private function uuid(string $binary): string
    {
        $hex = bin2hex($binary);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
