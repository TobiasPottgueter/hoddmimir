<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\CollectorReadModel;
use App\Application\Inventory\ReadModel\CollectorRun;
use App\Application\Inventory\ReadModel\CollectorScope;
use App\Application\Inventory\ReadModel\CollectorScopeQuery;
use App\Application\Inventory\ReadModel\CollectorStatus;
use App\Application\Inventory\ReadModel\InventoryOverview;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\InventoryReadModel;
use App\Application\Inventory\ReadModel\InventoryResource;
use App\Application\Inventory\ReadModel\InventoryResourceKind;
use App\Application\Inventory\ReadModel\InventoryResourceQuery;
use App\Application\Inventory\ReadModel\InventoryState;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadPage;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalInventoryReadModel implements InventoryReadModel, CollectorReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    public function overview(): InventoryOverview
    {
        $counts = [];
        foreach ([
            'pveConnections' => "SELECT COUNT(id) FROM proxmox_connections WHERE product = 'pve' AND enabled = 1",
            'pbsConnections' => "SELECT COUNT(id) FROM proxmox_connections WHERE product = 'pbs' AND enabled = 1",
            'pveClusters' => "SELECT COUNT(id) FROM pve_clusters WHERE inventory_state = 'active'",
            'pveNodes' => "SELECT COUNT(id) FROM pve_nodes WHERE inventory_state = 'active'",
            'qemuGuests' => "SELECT COUNT(id) FROM guests WHERE inventory_state = 'active' AND guest_type = 'qemu'",
            'lxcGuests' => "SELECT COUNT(id) FROM guests WHERE inventory_state = 'active' AND guest_type = 'lxc'",
            'pveStorages' => "SELECT COUNT(id) FROM pve_storages WHERE inventory_state = 'active'",
            'pbsServers' => 'SELECT COUNT(id) FROM pbs_servers',
            'pbsDatastores' => "SELECT COUNT(id) FROM pbs_datastores WHERE inventory_state = 'active'",
            'pbsNamespaces' => "SELECT COUNT(id) FROM pbs_namespaces WHERE inventory_state = 'active'",
            'pbsBackupGroups' => "SELECT COUNT(id) FROM pbs_backup_groups WHERE inventory_state = 'active'",
            'pbsSnapshots' => "SELECT COUNT(id) FROM pbs_snapshots WHERE inventory_state = 'active'",
        ] as $key => $sql) {
            $counts[$key] = $this->integer($this->connection->fetchOne($sql));
        }

        $latest = $this->connection->fetchOne(<<<'SQL'
            SELECT MAX(observed_at) FROM (
                SELECT MAX(last_seen_at) AS observed_at FROM pve_clusters
                UNION ALL SELECT MAX(last_seen_at) FROM pve_nodes
                UNION ALL SELECT MAX(last_seen_at) FROM guests
                UNION ALL SELECT MAX(last_seen_at) FROM pve_storages
                UNION ALL SELECT MAX(last_seen_at) FROM pbs_servers
                UNION ALL SELECT MAX(last_seen_at) FROM pbs_datastores
                UNION ALL SELECT MAX(last_seen_at) FROM pbs_namespaces
                UNION ALL SELECT MAX(last_seen_at) FROM pbs_backup_groups
                UNION ALL SELECT MAX(last_seen_at) FROM pbs_snapshots
            ) AS inventory_freshness
            SQL);

        return new InventoryOverview(
            $this->date($this->connection->fetchOne('SELECT UTC_TIMESTAMP(6)')),
            null === $latest ? null : $this->date($latest),
            $counts,
        );
    }

    public function resources(InventoryResourceQuery $query): ReadPage
    {
        if (InventoryResourceKind::PbsServer === $query->kind
            && InventoryState::Archived === $query->inventoryState) {
            return new ReadPage($query->page, [], null);
        }

        [$sql, $params, $types] = $this->resourceSql($query);
        $limit = $query->page->limit + 1;
        $sql .= sprintf(' LIMIT %d', $limit);
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $hasMore = count($rows) > $query->page->limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = array_map(
            fn (array $row): InventoryResource => $this->resource($query->kind, $row),
            $rows,
        );
        $last = [] === $items ? null : $items[array_key_last($items)];
        $nextCursor = $hasMore && null !== $last
            ? PageCursor::resource($query->cursorContext(), $last->displayName, $last->id)
            : null;

        return new ReadPage($query->page, $items, $nextCursor);
    }

    public function status(): CollectorStatus
    {
        $now = $this->date($this->connection->fetchOne('SELECT UTC_TIMESTAMP(6)'));
        $scheduleRow = $this->connection->fetchAssociative(<<<'SQL'
            SELECT interval_seconds, next_scan_at, last_cycle_started_at,
                   last_cycle_finished_at, lease_expires_at
            FROM collector_schedule
            WHERE schedule_name = 'inventory'
            SQL);
        $schedule = ['configured' => false];
        if (false !== $scheduleRow) {
            $leaseExpiresAt = $this->nullableDate($scheduleRow['lease_expires_at'] ?? null);
            $schedule = [
                'configured' => true,
                'intervalSeconds' => $this->integer($scheduleRow['interval_seconds'] ?? null),
                'nextScanAt' => $this->date($scheduleRow['next_scan_at'] ?? null),
                'lastCycleStartedAt' => $this->nullableDate($scheduleRow['last_cycle_started_at'] ?? null),
                'lastCycleFinishedAt' => $this->nullableDate($scheduleRow['last_cycle_finished_at'] ?? null),
                'leaseActive' => null !== $leaseExpiresAt && $leaseExpiresAt > $now,
                'leaseExpiresAt' => $leaseExpiresAt,
            ];
        }

        $heartbeatRow = $this->connection->fetchAssociative(<<<'SQL'
            SELECT status, started_at, heartbeat_at, expires_at, current_activity, next_action_at, build_version
            FROM worker_heartbeats
            WHERE worker_kind = 'collector'
            ORDER BY heartbeat_at DESC, worker_instance_id DESC
            LIMIT 1
            SQL);
        $heartbeat = null;
        if (false !== $heartbeatRow) {
            $expiresAt = $this->date($heartbeatRow['expires_at'] ?? null);
            $heartbeat = [
                'status' => $this->string($heartbeatRow['status'] ?? null),
                'startedAt' => $this->date($heartbeatRow['started_at'] ?? null),
                'heartbeatAt' => $this->date($heartbeatRow['heartbeat_at'] ?? null),
                'expiresAt' => $expiresAt,
                'fresh' => $expiresAt > $now,
                'currentActivity' => $this->nullableString($heartbeatRow['current_activity'] ?? null),
                'nextActionAt' => $this->nullableDate($heartbeatRow['next_action_at'] ?? null),
                'buildVersion' => $this->string($heartbeatRow['build_version'] ?? null),
            ];
        }

        return new CollectorStatus($now, $schedule, $heartbeat);
    }

    public function runs(PageRequest $page): ReadPage
    {
        $page->cursor?->assertContext(PageCursorKind::CollectorRun, PageCursor::collectorRunsContext());
        $where = '';
        $params = [];
        $types = [];
        if (null !== $page->cursor) {
            $where = <<<'SQL'
                WHERE run.started_at < :cursor_started_at
                   OR (run.started_at = :cursor_started_at AND run.id < :cursor_id)
                SQL;
            $params = [
                'cursor_started_at' => $this->databaseDate($page->cursor->first),
                'cursor_id' => (new \App\Application\Inventory\ReadModel\ReadModelIdentifier(
                    $page->cursor->second,
                ))->binary(),
            ];
            $types = ['cursor_id' => ParameterType::BINARY];
        }
        $rows = $this->connection->fetchAllAssociative(sprintf(<<<SQL
            SELECT run.id, run.connection_id, connection.display_name, connection.product,
                   run.status, run.authoritative, run.started_at, run.finished_at, run.applied_at,
                   run.nodes_seen, run.guests_seen, run.storages_seen, run.error_code
            FROM inventory_sync_runs AS run
            JOIN proxmox_connections AS connection ON connection.id = run.connection_id
            %s
            ORDER BY run.started_at DESC, run.id DESC
            LIMIT %d
            SQL, $where, $page->limit + 1), $params, $types);
        $hasMore = count($rows) > $page->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(fn (array $row): CollectorRun => new CollectorRun(
            $this->uuid($row['id'] ?? null),
            $this->uuid($row['connection_id'] ?? null),
            $this->string($row['display_name'] ?? null),
            $this->string($row['product'] ?? null),
            $this->string($row['status'] ?? null),
            $this->boolean($row['authoritative'] ?? null),
            $this->date($row['started_at'] ?? null),
            $this->nullableDate($row['finished_at'] ?? null),
            $this->nullableDate($row['applied_at'] ?? null),
            $this->integer($row['nodes_seen'] ?? null),
            $this->integer($row['guests_seen'] ?? null),
            $this->integer($row['storages_seen'] ?? null),
            $this->nullableString($row['error_code'] ?? null),
        ), $rows);
        $last = [] === $items ? null : $items[array_key_last($items)];
        $nextCursor = $hasMore && null !== $last ? PageCursor::collectorRun($last->startedAt, $last->id) : null;

        return new ReadPage($page, $items, $nextCursor);
    }

    public function scopes(CollectorScopeQuery $query): ReadPage
    {
        $cursorCondition = '';
        $params = ['run_id' => $query->runId->binary()];
        $types = ['run_id' => ParameterType::BINARY];
        if (null !== $query->page->cursor) {
            $cursorCondition = <<<'SQL'
                WHERE scope_type > :cursor_scope_type
                   OR (scope_type = :cursor_scope_type AND scope_key > :cursor_scope_key)
                SQL;
            $params['cursor_scope_type'] = $query->page->cursor->first;
            $params['cursor_scope_key'] = $query->page->cursor->second;
        }
        $rows = $this->connection->fetchAllAssociative(sprintf(<<<SQL
            SELECT sync_run_id, scope_type, scope_key, status, observed_at, error_code
            FROM (
                SELECT sync_run_id, scope_type, scope_key, status, observed_at, NULL AS error_code
                FROM inventory_sync_scope_results
                WHERE sync_run_id = :run_id
                UNION ALL
                SELECT content.parent_run_id AS sync_run_id, scope.scope_type,
                       CONCAT(scope.datastore_name, ':', scope.namespace_path) AS scope_key,
                       scope.status,
                       COALESCE(content.applied_at, content.finished_at, content.started_at) AS observed_at,
                       scope.error_code
                FROM pbs_content_scope_results AS scope
                JOIN pbs_content_runs AS content ON content.id = scope.content_run_id
                WHERE content.parent_run_id = :run_id
                UNION ALL
                SELECT monitoring.parent_sync_run_id AS sync_run_id, scope.scope_type,
                       CONCAT(monitoring.product, ':', scope.scope_key, ':',
                              scope.source_kind, ':', scope.filter_value) AS scope_key,
                       scope.status, scope.observed_at, scope.error_code
                FROM proxmox_monitoring_scope_results AS scope
                JOIN proxmox_monitoring_runs AS monitoring ON monitoring.id = scope.monitoring_run_id
                WHERE monitoring.parent_sync_run_id = :run_id
            ) AS combined_scope
            %s
            ORDER BY scope_type, scope_key
            LIMIT %d
            SQL, $cursorCondition, $query->page->limit + 1), $params, $types);
        $hasMore = count($rows) > $query->page->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(fn (array $row): CollectorScope => new CollectorScope(
            $this->uuid($row['sync_run_id'] ?? null),
            $this->string($row['scope_type'] ?? null),
            $this->string($row['scope_key'] ?? null),
            $this->string($row['status'] ?? null),
            $this->date($row['observed_at'] ?? null),
            $this->nullableErrorCode($row['error_code'] ?? null),
        ), $rows);
        $last = [] === $items ? null : $items[array_key_last($items)];
        $nextCursor = $hasMore && null !== $last
            ? PageCursor::collectorScope($query->cursorContext(), $last->scopeType, $last->scopeKey)
            : null;

        return new ReadPage($query->page, $items, $nextCursor);
    }

    /** @return array{string, array<string, mixed>, array<string, ParameterType>} */
    private function resourceSql(InventoryResourceQuery $query): array
    {
        [$sql, $displayExpression] = match ($query->kind) {
            InventoryResourceKind::PveCluster => [$this->pveClusterSql(), 'COALESCE(resource.external_name, connection.display_name)'],
            InventoryResourceKind::PveNode => [$this->pveNodeSql(), 'resource.node_name'],
            InventoryResourceKind::PveGuest => [$this->pveGuestSql(), "COALESCE(resource.name, CONCAT(resource.guest_type, '-', resource.vmid))"],
            InventoryResourceKind::PveStorage => [$this->pveStorageSql(), 'resource.storage_name'],
            InventoryResourceKind::PbsServer => [$this->pbsServerSql(), 'resource.node_name'],
            InventoryResourceKind::PbsDatastore => [$this->pbsDatastoreSql(), 'resource.datastore_name'],
            InventoryResourceKind::PbsNamespace => [$this->pbsNamespaceSql(), "CASE WHEN resource.namespace_path = '' THEN '@root' ELSE resource.namespace_path END"],
            InventoryResourceKind::PbsBackupGroup => [$this->pbsBackupGroupSql(), "CONCAT(resource.backup_type, '/', resource.backup_id)"],
            InventoryResourceKind::PbsSnapshot => [$this->pbsSnapshotSql(), "DATE_FORMAT(resource.backup_time, '%Y-%m-%dT%H:%i:%s.%fZ')"],
        };
        $alias = 'resource';
        $conditions = [];
        $params = [];
        $types = [];
        if (null !== $query->connectionId) {
            $conditions[] = $alias.'.connection_id = :connection_id';
            $params['connection_id'] = $query->connectionId->binary();
            $types['connection_id'] = ParameterType::BINARY;
        }
        if (null !== $query->inventoryState && InventoryResourceKind::PbsServer !== $query->kind) {
            $conditions[] = $alias.'.inventory_state = :inventory_state';
            $params['inventory_state'] = $query->inventoryState->value;
        }
        if (null !== $query->parentId) {
            $parentExpression = match ($query->kind) {
                InventoryResourceKind::PbsDatastore => 'resource.server_id',
                InventoryResourceKind::PbsNamespace => <<<'SQL'
                    CASE
                        WHEN resource.namespace_depth = 0 THEN resource.datastore_id
                        ELSE resource.parent_namespace_id
                    END
                    SQL,
                InventoryResourceKind::PbsBackupGroup => 'resource.namespace_id',
                InventoryResourceKind::PbsSnapshot => 'resource.group_id',
                default => 'resource.cluster_id',
            };
            $conditions[] = $parentExpression.' = :parent_id';
            $params['parent_id'] = $query->parentId->binary();
            $types['parent_id'] = ParameterType::BINARY;
        }
        if (null !== $query->guestType) {
            $conditions[] = $alias.'.guest_type = :guest_type';
            $params['guest_type'] = $query->guestType;
        }
        if (null !== $query->page->cursor) {
            $conditions[] = sprintf(
                '(%1$s > :cursor_display OR (%1$s = :cursor_display AND resource.id > :cursor_id))',
                $displayExpression,
            );
            $params['cursor_display'] = $query->page->cursor->first;
            $params['cursor_id'] = (new \App\Application\Inventory\ReadModel\ReadModelIdentifier(
                $query->page->cursor->second,
            ))->binary();
            $types['cursor_id'] = ParameterType::BINARY;
        }
        if ([] !== $conditions) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY display_name, resource.id';

        return [$sql, $params, $types];
    }

    private function pveClusterSql(): string
    {
        return <<<'SQL'
            SELECT resource.id, resource.connection_id, resource.external_name, resource.topology,
                   resource.inventory_state, resource.first_seen_at, resource.last_seen_at, resource.archived_at,
                   connection.display_name AS connection_name,
                   COALESCE(resource.external_name, connection.display_name) AS display_name
            FROM pve_clusters AS resource
            JOIN proxmox_connections AS connection ON connection.id = resource.connection_id
            SQL;
    }

    private function pveNodeSql(): string
    {
        return <<<'SQL'
            SELECT resource.id, resource.connection_id, resource.cluster_id, resource.node_name,
                   resource.api_status, resource.inventory_state, resource.first_seen_at,
                   resource.last_seen_at, resource.archived_at, connection.display_name AS connection_name,
                   resource.node_name AS display_name
            FROM pve_nodes AS resource
            JOIN proxmox_connections AS connection ON connection.id = resource.connection_id
            SQL;
    }

    private function pveGuestSql(): string
    {
        return <<<'SQL'
            SELECT resource.id, resource.connection_id, resource.cluster_id, resource.guest_type,
                   resource.vmid, resource.name, resource.is_template, resource.inventory_state,
                   resource.first_seen_at, resource.last_seen_at, resource.archived_at,
                   connection.display_name AS connection_name,
                   COALESCE(resource.name, CONCAT(resource.guest_type, '-', resource.vmid)) AS display_name,
                   CASE WHEN resource.inventory_state = 'active' THEN placement.node_id ELSE NULL END AS node_id,
                   CASE WHEN resource.inventory_state = 'active' THEN placement.observed_at ELSE NULL END AS state_observed_at,
                   CASE WHEN resource.inventory_state = 'active' THEN node.node_name ELSE NULL END AS node_name
            FROM guests AS resource
            JOIN proxmox_connections AS connection ON connection.id = resource.connection_id
            LEFT JOIN guest_placements AS placement ON placement.guest_id = resource.id
            LEFT JOIN pve_nodes AS node ON node.id = placement.node_id
            SQL;
    }

    private function pveStorageSql(): string
    {
        return <<<'SQL'
            SELECT resource.id, resource.connection_id, resource.cluster_id, resource.storage_name,
                   resource.storage_type, resource.supports_backup, resource.shared, resource.disabled,
                   resource.content_json, resource.inventory_state, resource.first_seen_at,
                   resource.last_seen_at, resource.archived_at, connection.display_name AS connection_name,
                   resource.storage_name AS display_name,
                   mapping.server AS pbs_server, mapping.port AS pbs_port,
                   mapping.datastore AS pbs_datastore, mapping.namespace AS pbs_namespace,
                   mapping.observed_at AS state_observed_at
            FROM pve_storages AS resource
            JOIN proxmox_connections AS connection ON connection.id = resource.connection_id
            LEFT JOIN pve_storage_pbs_mappings AS mapping ON mapping.storage_id = resource.id
            SQL;
    }

    private function pbsServerSql(): string
    {
        return <<<'SQL'
            SELECT resource.id, resource.connection_id, resource.node_name, resource.version_text,
                   resource.release_text, resource.repo_id, resource.first_seen_at, resource.last_seen_at,
                   connection.display_name AS connection_name,
                   resource.node_name AS display_name, status.observed_at AS state_observed_at,
                   status.uptime_seconds, status.memory_total_bytes, status.memory_used_bytes,
                   status.root_total_bytes, status.root_used_bytes, status.root_available_bytes
            FROM pbs_servers AS resource
            JOIN proxmox_connections AS connection ON connection.id = resource.connection_id
            LEFT JOIN pbs_server_status AS status ON status.server_id = resource.id
            SQL;
    }

    private function pbsDatastoreSql(): string
    {
        return <<<'SQL'
            SELECT resource.id, resource.connection_id, resource.server_id, resource.datastore_name,
                   resource.backend_type, resource.mount_status, resource.maintenance_mode,
                   resource.allows_backup_writes, resource.inventory_state, resource.first_seen_at,
                   resource.last_seen_at, resource.archived_at, connection.display_name AS connection_name,
                   resource.datastore_name AS display_name, capacity.observed_at AS state_observed_at,
                   capacity.semantics, capacity.total_bytes, capacity.used_bytes, capacity.available_bytes
            FROM pbs_datastores AS resource
            JOIN proxmox_connections AS connection ON connection.id = resource.connection_id
            LEFT JOIN pbs_datastore_capacity_state AS capacity ON capacity.datastore_id = resource.id
            SQL;
    }

    private function pbsNamespaceSql(): string
    {
        return <<<'SQL'
            SELECT resource.id, resource.connection_id, resource.datastore_id, resource.parent_namespace_id,
                   resource.namespace_path, resource.namespace_depth, resource.inventory_state,
                   resource.first_seen_at, resource.last_seen_at, resource.archived_at,
                   connection.display_name AS connection_name,
                   CASE WHEN resource.namespace_path = '' THEN '@root' ELSE resource.namespace_path END AS display_name
            FROM pbs_namespaces AS resource
            JOIN proxmox_connections AS connection ON connection.id = resource.connection_id
            SQL;
    }

    private function pbsBackupGroupSql(): string
    {
        return <<<'SQL'
            SELECT resource.id, resource.connection_id, resource.namespace_id, resource.backup_type,
                   resource.backup_id, resource.inventory_state, resource.first_seen_at,
                   resource.last_seen_at, resource.archived_at, connection.display_name AS connection_name,
                   CONCAT(resource.backup_type, '/', resource.backup_id) AS display_name
            FROM pbs_backup_groups AS resource
            JOIN proxmox_connections AS connection ON connection.id = resource.connection_id
            SQL;
    }

    private function pbsSnapshotSql(): string
    {
        return <<<'SQL'
            SELECT resource.id, resource.connection_id, resource.group_id, resource.backup_time,
                   resource.protected, resource.size_bytes, resource.verification_state,
                   resource.inventory_state, resource.first_seen_at, resource.last_seen_at,
                   resource.archived_at, connection.display_name AS connection_name,
                   DATE_FORMAT(resource.backup_time, '%Y-%m-%dT%H:%i:%s.%fZ') AS display_name
            FROM pbs_snapshots AS resource
            JOIN proxmox_connections AS connection ON connection.id = resource.connection_id
            SQL;
    }

    /** @param array<string, mixed> $row */
    private function resource(InventoryResourceKind $kind, array $row): InventoryResource
    {
        $attributes = match ($kind) {
            InventoryResourceKind::PveCluster => ['topology' => $this->string($row['topology'] ?? null)],
            InventoryResourceKind::PveNode => ['apiStatus' => $this->string($row['api_status'] ?? null)],
            InventoryResourceKind::PveGuest => [
                'guestType' => $this->string($row['guest_type'] ?? null),
                'vmid' => $this->integer($row['vmid'] ?? null),
                'template' => $this->nullableBoolean($row['is_template'] ?? null),
                'nodeId' => $this->nullableUuid($row['node_id'] ?? null),
                'nodeName' => $this->nullableString($row['node_name'] ?? null),
            ],
            InventoryResourceKind::PveStorage => [
                'storageType' => $this->string($row['storage_type'] ?? null),
                'supportsBackup' => $this->boolean($row['supports_backup'] ?? null),
                'shared' => $this->boolean($row['shared'] ?? null),
                'disabled' => $this->boolean($row['disabled'] ?? null),
                'content' => $this->stringList($row['content_json'] ?? null),
                'pbsServer' => $this->nullableString($row['pbs_server'] ?? null),
                'pbsPort' => $this->nullableInteger($row['pbs_port'] ?? null),
                'pbsDatastore' => $this->nullableString($row['pbs_datastore'] ?? null),
                'pbsNamespace' => $this->nullableString($row['pbs_namespace'] ?? null),
            ],
            InventoryResourceKind::PbsServer => [
                'nodeName' => $this->string($row['node_name'] ?? null),
                'version' => $this->string($row['version_text'] ?? null),
                'release' => $this->string($row['release_text'] ?? null),
                'repository' => $this->string($row['repo_id'] ?? null),
                'uptimeSeconds' => $this->nullableInteger($row['uptime_seconds'] ?? null),
                'memoryTotalBytes' => $this->nullableInteger($row['memory_total_bytes'] ?? null),
                'memoryUsedBytes' => $this->nullableInteger($row['memory_used_bytes'] ?? null),
                'rootTotalBytes' => $this->nullableInteger($row['root_total_bytes'] ?? null),
                'rootUsedBytes' => $this->nullableInteger($row['root_used_bytes'] ?? null),
                'rootAvailableBytes' => $this->nullableInteger($row['root_available_bytes'] ?? null),
            ],
            InventoryResourceKind::PbsDatastore => [
                'backendType' => $this->string($row['backend_type'] ?? null),
                'mountStatus' => $this->string($row['mount_status'] ?? null),
                'maintenanceMode' => $this->nullableString($row['maintenance_mode'] ?? null),
                'allowsBackupWrites' => $this->boolean($row['allows_backup_writes'] ?? null),
                'capacitySemantics' => $this->nullableString($row['semantics'] ?? null),
                'totalBytes' => $this->nullableInteger($row['total_bytes'] ?? null),
                'usedBytes' => $this->nullableInteger($row['used_bytes'] ?? null),
                'availableBytes' => $this->nullableInteger($row['available_bytes'] ?? null),
            ],
            InventoryResourceKind::PbsNamespace => [
                'datastoreId' => $this->uuid($row['datastore_id'] ?? null),
                'namespacePath' => $this->string($row['namespace_path'] ?? null),
                'namespaceDepth' => $this->integer($row['namespace_depth'] ?? null),
                'parentNamespaceId' => $this->nullableUuid($row['parent_namespace_id'] ?? null),
            ],
            InventoryResourceKind::PbsBackupGroup => [
                'namespaceId' => $this->uuid($row['namespace_id'] ?? null),
                'backupType' => $this->string($row['backup_type'] ?? null),
                'backupId' => $this->string($row['backup_id'] ?? null),
            ],
            InventoryResourceKind::PbsSnapshot => [
                'backupTime' => $this->date($row['backup_time'] ?? null),
                'protected' => $this->boolean($row['protected'] ?? null),
                'sizeBytes' => $this->nullableInteger($row['size_bytes'] ?? null),
                'verificationState' => $this->nullableString($row['verification_state'] ?? null),
            ],
        };

        return new InventoryResource(
            $this->uuid($row['id'] ?? null),
            $kind,
            $this->uuid($row['connection_id'] ?? null),
            $this->string($row['connection_name'] ?? null),
            match ($kind) {
                InventoryResourceKind::PveNode,
                InventoryResourceKind::PveGuest,
                InventoryResourceKind::PveStorage => $this->uuid($row['cluster_id'] ?? null),
                InventoryResourceKind::PbsDatastore => $this->uuid($row['server_id'] ?? null),
                InventoryResourceKind::PbsNamespace => 0 === $this->integer($row['namespace_depth'] ?? null)
                    ? $this->uuid($row['datastore_id'] ?? null)
                    : $this->nullableUuid($row['parent_namespace_id'] ?? null),
                InventoryResourceKind::PbsBackupGroup => $this->uuid($row['namespace_id'] ?? null),
                InventoryResourceKind::PbsSnapshot => $this->uuid($row['group_id'] ?? null),
                InventoryResourceKind::PveCluster, InventoryResourceKind::PbsServer => null,
            },
            $this->string($row['display_name'] ?? null),
            InventoryResourceKind::PbsServer === $kind
                ? InventoryState::Active
                : InventoryState::from($this->string($row['inventory_state'] ?? null)),
            $this->date($row['first_seen_at'] ?? null),
            $this->date($row['last_seen_at'] ?? null),
            $this->nullableDate($row['archived_at'] ?? null),
            $this->nullableDate($row['state_observed_at'] ?? null),
            $attributes,
        );
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid inventory identifier.');
        }
        $hex = bin2hex($value);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    private function nullableUuid(mixed $value): ?string
    {
        return null === $value ? null : $this->uuid($value);
    }

    private function date(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        }
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid UTC timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date) {
            throw new RuntimeException('MariaDB returned an invalid UTC timestamp.');
        }
        return $date->format('Y-m-d\TH:i:s.u\Z');
    }

    private function nullableDate(mixed $value): ?string
    {
        return null === $value ? null : $this->date($value);
    }

    private function databaseDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new RuntimeException('The pagination cursor contains an invalid UTC timestamp.');
        }

        return $date->format('Y-m-d H:i:s.u');
    }

    private function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid inventory string.');
        }
        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return null === $value ? null : $this->string($value);
    }

    private function nullableErrorCode(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $errorCode = $this->string($value);
        $length = strlen($errorCode);
        if ($length < 1 || $length > 64
            || $length !== strspn($errorCode, 'abcdefghijklmnopqrstuvwxyz0123456789_')) {
            throw new RuntimeException('MariaDB returned an invalid collector error code.');
        }

        return $errorCode;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new RuntimeException('MariaDB returned an invalid inventory integer.');
    }

    private function nullableInteger(mixed $value): ?int
    {
        return null === $value ? null : $this->integer($value);
    }

    private function boolean(mixed $value): bool
    {
        $integer = $this->integer($value);
        if (0 !== $integer && 1 !== $integer) {
            throw new RuntimeException('MariaDB returned an invalid inventory boolean.');
        }
        return 1 === $integer;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        return null === $value ? null : $this->boolean($value);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned invalid inventory JSON.');
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('MariaDB returned invalid inventory JSON.');
        }
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                throw new RuntimeException('MariaDB returned invalid inventory JSON.');
            }
        }
        return array_values($decoded);
    }
}
