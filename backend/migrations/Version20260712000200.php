<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000200 extends AbstractMigration
{
    /** @var array<string, list<string>> */
    private const array SELECT_COLUMNS = [
        'proxmox_connections' => ['id', 'display_name', 'product', 'enabled'],
        'collector_schedule' => [
            'schedule_name', 'interval_seconds', 'next_scan_at', 'last_cycle_started_at',
            'last_cycle_finished_at', 'lease_expires_at',
        ],
        'worker_heartbeats' => [
            'worker_instance_id', 'worker_kind', 'status', 'started_at', 'heartbeat_at', 'expires_at',
            'current_activity', 'next_action_at', 'build_version',
        ],
        'inventory_sync_runs' => [
            'id', 'connection_id', 'status', 'authoritative', 'started_at', 'finished_at', 'applied_at',
            'nodes_seen', 'guests_seen', 'storages_seen', 'error_code',
        ],
        'inventory_sync_scope_results' => ['sync_run_id', 'scope_type', 'scope_key', 'status', 'observed_at'],
        'pve_clusters' => [
            'id', 'connection_id', 'external_name', 'topology', 'inventory_state',
            'first_seen_at', 'last_seen_at', 'archived_at',
        ],
        'pve_nodes' => [
            'id', 'connection_id', 'cluster_id', 'node_name', 'api_status', 'inventory_state',
            'first_seen_at', 'last_seen_at', 'archived_at',
        ],
        'guests' => [
            'id', 'connection_id', 'cluster_id', 'guest_type', 'vmid', 'name', 'is_template',
            'inventory_state', 'first_seen_at', 'last_seen_at', 'archived_at',
        ],
        'guest_placements' => ['guest_id', 'node_id', 'observed_at'],
        'pve_storages' => [
            'id', 'connection_id', 'cluster_id', 'storage_name', 'storage_type', 'supports_backup',
            'shared', 'disabled', 'content_json', 'inventory_state', 'first_seen_at', 'last_seen_at', 'archived_at',
        ],
        'pve_storage_pbs_mappings' => ['storage_id', 'server', 'port', 'datastore', 'namespace', 'observed_at'],
        'pbs_servers' => [
            'id', 'connection_id', 'node_name', 'version_text', 'release_text', 'repo_id',
            'first_seen_at', 'last_seen_at',
        ],
        'pbs_server_status' => [
            'server_id', 'observed_at', 'uptime_seconds', 'memory_total_bytes', 'memory_used_bytes',
            'root_total_bytes', 'root_used_bytes', 'root_available_bytes',
        ],
        'pbs_datastores' => [
            'id', 'connection_id', 'server_id', 'datastore_name', 'backend_type', 'mount_status',
            'maintenance_mode', 'allows_backup_writes', 'inventory_state', 'first_seen_at',
            'last_seen_at', 'archived_at',
        ],
        'pbs_datastore_capacity_state' => [
            'datastore_id', 'observed_at', 'semantics', 'total_bytes', 'used_bytes', 'available_bytes',
        ],
        'pbs_content_runs' => ['id', 'parent_run_id', 'started_at', 'finished_at', 'applied_at'],
        'pbs_content_scope_results' => [
            'content_run_id', 'datastore_name', 'scope_type', 'namespace_path', 'status', 'error_code',
        ],
        'pbs_namespaces' => [
            'id', 'connection_id', 'datastore_id', 'namespace_path', 'namespace_depth',
            'parent_namespace_id', 'inventory_state', 'first_seen_at', 'last_seen_at', 'archived_at',
        ],
        'pbs_backup_groups' => [
            'id', 'connection_id', 'namespace_id', 'backup_type', 'backup_id', 'inventory_state',
            'first_seen_at', 'last_seen_at', 'archived_at',
        ],
        'pbs_snapshots' => [
            'id', 'connection_id', 'group_id', 'backup_time', 'protected', 'size_bytes',
            'verification_state', 'inventory_state', 'first_seen_at', 'last_seen_at', 'archived_at',
        ],
        'proxmox_monitoring_runs' => ['id', 'parent_sync_run_id', 'product'],
        'proxmox_monitoring_scope_results' => [
            'monitoring_run_id', 'scope_type', 'scope_key', 'source_kind', 'filter_value',
            'status', 'error_code', 'observed_at',
        ],
    ];

    public function getDescription(): string
    {
        return 'Grant the WebApp least-privilege SELECT access for the versioned read-only inventory API.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_sync_runs ADD INDEX idx_inventory_sync_runs_started_id (started_at, id)');
        foreach (self::SELECT_COLUMNS as $table => $columns) {
            $this->privilege('GRANT', $table, $columns);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::SELECT_COLUMNS as $table => $columns) {
            $this->privilege('REVOKE', $table, $columns);
        }
        $this->addSql('ALTER TABLE inventory_sync_runs DROP INDEX idx_inventory_sync_runs_started_id');
    }

    /** @param list<string> $columns */
    private function privilege(string $operation, string $table, array $columns): void
    {
        $database = $this->connection->getDatabase();
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)
            || !isset(self::SELECT_COLUMNS[$table])
            || self::SELECT_COLUMNS[$table] !== $columns) {
            throw new \RuntimeException('A WebApp inventory grant identifier is invalid.');
        }
        foreach ($columns as $column) {
            if (1 !== preg_match('/^[a-z_]+$/D', $column)) {
                throw new \RuntimeException('A WebApp inventory grant identifier is invalid.');
            }
        }
        $quotedColumns = implode(', ', array_map(
            static fn (string $column): string => '`'.$column.'`',
            $columns,
        ));

        $this->addSql(sprintf(
            "%s SELECT (%s) ON `%s`.`%s` %s 'hoddmimir_web'@'%%'",
            $operation,
            $quotedColumns,
            $database,
            $table,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
    }
}
