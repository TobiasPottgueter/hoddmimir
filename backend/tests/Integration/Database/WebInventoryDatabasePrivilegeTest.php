<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class WebInventoryDatabasePrivilegeTest extends DatabaseTestCase
{
    public function testWebUserHasOnlyThePublishedInventoryAndConnectionAdministrationSurface(): void
    {
        $web = $this->webConnection();
        try {
            foreach ([
                'SELECT id, display_name, product, enabled FROM proxmox_connections LIMIT 0',
                'SELECT schedule_name, interval_seconds, lease_expires_at FROM collector_schedule LIMIT 0',
                'SELECT worker_instance_id, status, heartbeat_at FROM worker_heartbeats LIMIT 0',
                'SELECT id, connection_id, started_at, error_code FROM inventory_sync_runs LIMIT 0',
                'SELECT sync_run_id, scope_type, scope_key FROM inventory_sync_scope_results LIMIT 0',
                'SELECT id, connection_id, topology FROM pve_clusters LIMIT 0',
                'SELECT id, cluster_id, node_name FROM pve_nodes LIMIT 0',
                'SELECT id, guest_type, vmid, provisioned_size_bytes FROM guests LIMIT 0',
                'SELECT guest_id, node_id, placement_revision, observed_at FROM guest_placements LIMIT 0',
                'SELECT id, storage_name, content_json FROM pve_storages LIMIT 0',
                'SELECT node_allowlist_json FROM pve_storages LIMIT 0',
                'SELECT connection_id, cluster_id, node_id, storage_id, enabled, active, capacity_status, total_bytes, used_bytes, available_bytes, observed_at FROM pve_node_storage_state LIMIT 0',
                'SELECT id, connection_id, host, port, enabled FROM proxmox_connection_endpoints LIMIT 0',
                'SELECT priority, tls_mode, custom_ca_pem, sha256_fingerprint, last_attempted_at, last_success_at, last_error_code FROM proxmox_connection_endpoints LIMIT 0',
                'SELECT id, connection_id, purpose, principal, token_name, revision, rotated_at, updated_at FROM proxmox_credentials LIMIT 0',
                'SELECT storage_id, server, datastore, namespace FROM pve_storage_pbs_mappings LIMIT 0',
                'SELECT id, connection_id, node_name FROM pbs_servers LIMIT 0',
                'SELECT server_id, observed_at, uptime_seconds FROM pbs_server_status LIMIT 0',
                'SELECT id, server_id, datastore_name FROM pbs_datastores LIMIT 0',
                'SELECT datastore_id, semantics, total_bytes FROM pbs_datastore_capacity_state LIMIT 0',
                'SELECT id, parent_run_id, applied_at FROM pbs_content_runs LIMIT 0',
                'SELECT content_run_id, scope_type, error_code FROM pbs_content_scope_results LIMIT 0',
                'SELECT id, server_id, datastore_id, namespace_path FROM pbs_namespaces LIMIT 0',
                'SELECT id, namespace_id, backup_type, backup_id FROM pbs_backup_groups LIMIT 0',
                'SELECT id, group_id, backup_time, verification_state FROM pbs_snapshots LIMIT 0',
                'SELECT id, parent_sync_run_id, product FROM proxmox_monitoring_runs LIMIT 0',
                'SELECT monitoring_run_id, scope_type, error_code FROM proxmox_monitoring_scope_results LIMIT 0',
                'SELECT connection_id, product, version_major, version_minor, version_patch, raw_version, last_observed_at, id FROM proxmox_capability_snapshots LIMIT 0',
            ] as $sql) {
                self::assertSame([], $web->fetchAllAssociative($sql));
            }

            foreach ([
                'SELECT * FROM proxmox_credentials',
                'SELECT * FROM collector_schedule',
                'SELECT lease_owner FROM collector_schedule',
                'SELECT lease_token FROM collector_schedule',
                'SELECT lease_fencing_token FROM collector_schedule',
                'SELECT current_cycle_token FROM worker_heartbeats',
                'SELECT cycle_token FROM inventory_sync_runs',
                'SELECT collector_fencing_token FROM inventory_sync_runs',
                'SELECT endpoint_id FROM inventory_sync_runs',
                'SELECT capability_snapshot_id FROM inventory_sync_runs',
                'SELECT error_summary FROM inventory_sync_runs',
                'SELECT * FROM pve_node_storage_state',
                'SELECT * FROM guest_write_states',
                'SELECT sync_run_id FROM guest_placements',
                'SELECT owner_auth_id FROM pbs_backup_groups',
                'SELECT * FROM pbs_namespaces',
                'SELECT comment FROM pbs_snapshots',
                'SELECT encryption_fingerprint FROM pbs_snapshots',
                'SELECT verification_upid FROM pbs_snapshots',
                'SELECT files_json FROM pbs_snapshots',
                'SELECT * FROM pbs_snapshots',
                'SELECT cycle_token, collector_fencing_token, endpoint_id FROM pbs_content_runs',
                'SELECT connection_id, rows_read FROM pbs_content_scope_results',
                'SELECT endpoint_id, cycle_token, collector_fencing_token FROM proxmox_monitoring_runs',
                'SELECT connection_id, pages_read, rows_read, items_seen FROM proxmox_monitoring_scope_results',
            ] as $sql) {
                $this->assertDenied(static fn () => $web->fetchAllAssociative($sql));
            }
            self::assertSame(0, $web->executeStatement(
                "UPDATE proxmox_connections SET display_name = 'test', enabled = 0, revision = 1, updated_at = '2026-07-13 00:00:00.000000' WHERE 1 = 0",
            ));
            self::assertSame(0, $web->executeStatement(
                "UPDATE proxmox_connection_endpoints SET host = 'pve.example.test', port = 8006, priority = 100, tls_mode = 'system_ca', custom_ca_pem = NULL, sha256_fingerprint = NULL, enabled = 0, updated_at = '2026-07-13 00:00:00.000000' WHERE 1 = 0",
            ));
            self::assertSame(0, $web->executeStatement(
                "UPDATE proxmox_credentials SET principal = 'reader@pve', token_name = 'collector', secret_envelope = 'opaque', envelope_version = 1, key_id = 'key', revision = 1, rotated_at = '2026-07-13 00:00:00.000000', updated_at = '2026-07-13 00:00:00.000000' WHERE 1 = 0",
            ));
            self::assertSame(0, $web->executeStatement(
                "UPDATE backup_policies SET failure_notification_recipients_json = '[]' WHERE 1 = 0",
            ));
            self::assertSame(0, $web->executeStatement(<<<'SQL'
UPDATE backup_policies
SET target_id = target_id,
    display_name = display_name,
    status = status,
    revision = revision,
    policy_priority = policy_priority,
    backup_mode = backup_mode,
    compression = compression,
    maximum_age_seconds = maximum_age_seconds,
    bytes_written_threshold = bytes_written_threshold,
    cooldown_seconds = cooldown_seconds,
    schedule = schedule,
    legacy_maxfiles = legacy_maxfiles,
    keep_all = keep_all,
    keep_last = keep_last,
    keep_hourly = keep_hourly,
    keep_daily = keep_daily,
    keep_weekly = keep_weekly,
    keep_monthly = keep_monthly,
    keep_yearly = keep_yearly,
    retention_execution_enabled = retention_execution_enabled,
    failure_notification_recipients_json = failure_notification_recipients_json,
    updated_at = updated_at,
    disabled_at = disabled_at
WHERE 1 = 0
SQL));
            foreach ([
                'UPDATE proxmox_connections SET id = id WHERE 1 = 0',
                'UPDATE proxmox_connections SET product = product WHERE 1 = 0',
                'UPDATE proxmox_connection_endpoints SET id = id WHERE 1 = 0',
                'UPDATE proxmox_connection_endpoints SET connection_id = connection_id WHERE 1 = 0',
                'UPDATE proxmox_credentials SET id = id WHERE 1 = 0',
                'UPDATE proxmox_credentials SET connection_id = connection_id WHERE 1 = 0',
                'UPDATE proxmox_credentials SET purpose = purpose WHERE 1 = 0',
                'UPDATE proxmox_credentials SET enabled = enabled WHERE 1 = 0',
                'UPDATE backup_policies SET id = id WHERE 1 = 0',
                'UPDATE backup_policies SET connection_id = connection_id WHERE 1 = 0',
                'UPDATE backup_policies SET cluster_id = cluster_id WHERE 1 = 0',
            ] as $sql) {
                $this->assertDenied(static fn () => $web->executeStatement($sql));
            }
            foreach (['proxmox_connections', 'proxmox_connection_endpoints', 'proxmox_credentials'] as $table) {
                $this->assertDenied(static fn () => $web->executeStatement(
                    sprintf('DELETE FROM %s WHERE 1 = 0', $table),
                ));
            }
            $this->assertDenied(static fn () => $web->executeStatement(
                'DELETE FROM guests WHERE 1 = 0',
            ));
            foreach ([
                'pbs_servers' => 'id',
                'pbs_server_status' => 'server_id',
                'pbs_datastores' => 'id',
                'pbs_datastore_capacity_state' => 'datastore_id',
                'pbs_content_runs' => 'id',
                'pbs_content_scope_results' => 'content_run_id',
                'pbs_namespaces' => 'id',
                'pbs_backup_groups' => 'id',
                'pbs_snapshots' => 'id',
                'proxmox_monitoring_runs' => 'id',
                'proxmox_monitoring_scope_results' => 'monitoring_run_id',
            ] as $table => $column) {
                $this->assertDenied(static fn () => $web->executeStatement(
                    sprintf('UPDATE %s SET %s = %s WHERE 1 = 0', $table, $column, $column),
                ));
                $this->assertDenied(static fn () => $web->executeStatement(
                    sprintf('DELETE FROM %s WHERE 1 = 0', $table),
                ));
            }
        } finally {
            $web->close();
        }
    }

    private function webConnection(): Connection
    {
        $password = file_get_contents('/run/secrets/mariadb_web_password');
        self::assertIsString($password);

        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => 'hoddmimir_web',
            'password' => trim($password),
        ]));
    }

    /** @param callable(): mixed $operation */
    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('The WebApp database user exceeded its read-only inventory grants.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
