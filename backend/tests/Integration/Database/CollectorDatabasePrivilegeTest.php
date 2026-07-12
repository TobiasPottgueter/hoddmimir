<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\EndpointId;
use App\Infrastructure\Persistence\MariaDb\DbalPveEndpointReadConfigurationSource;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class CollectorDatabasePrivilegeTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-11 10:00:00.000000';

    public function testCollectorHasOnlyCurrentGuestWriteStateMutationGrants(): void
    {
        $collector = $this->collectorConnection();
        try {
            $collector->beginTransaction();
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $guestId = random_bytes(16);
            $connectionId = random_bytes(16);
            $clusterId = random_bytes(16);
            $runId = random_bytes(16);
            $collector->insert('guest_write_states', [
                'guest_id' => $guestId,
                'connection_id' => $connectionId,
                'cluster_id' => $clusterId,
                'diskwrite_bytes' => 123,
                'observed_at' => self::NOW,
                'authoritative_sync_run_id' => $runId,
            ]);
            $storedBytes = $collector->fetchOne(
                'SELECT diskwrite_bytes FROM guest_write_states WHERE guest_id = :guest_id',
                ['guest_id' => $guestId],
            );
            self::assertTrue(is_int($storedBytes) || is_string($storedBytes));
            self::assertSame('123', (string) $storedBytes);
            self::assertSame(1, $collector->update(
                'guest_write_states',
                ['diskwrite_bytes' => 100],
                ['guest_id' => $guestId],
            ));
            $this->assertDenied(static fn () => $collector->delete(
                'guest_write_states',
                ['guest_id' => $guestId],
            ));
            $this->assertDenied(static fn () => $collector->executeStatement(
                'DELETE FROM guest_placements WHERE 1 = 0',
            ));
            $collector->rollBack();
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        } finally {
            if ($collector->isTransactionActive()) {
                $collector->rollBack();
            }
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $collector->close();
        }
    }

    public function testCollectorCanReadOnlyItsCredentialViewAndCannotMutateConfiguration(): void
    {
        $connectionId = random_bytes(16);
        $endpointId = random_bytes(16);
        $this->seedBothCredentialPurposes($connectionId, $endpointId);
        $this->connection()->commit();

        $collector = $this->collectorConnection();
        try {
            self::assertSame(['collector'], $collector->fetchFirstColumn(
                'SELECT purpose FROM collector_credentials WHERE connection_id = :connection_id',
                ['connection_id' => $connectionId],
            ));
            $configuration = (new DbalPveEndpointReadConfigurationSource($collector))->load(
                new ConnectionId($connectionId),
                new EndpointId($endpointId),
                1,
            );
            self::assertSame('collector-pve.example.test', $configuration->host);

            $collector->beginTransaction();
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $storageId = random_bytes(16);
            $clusterId = random_bytes(16);
            $nodeId = random_bytes(16);
            $runId = random_bytes(16);
            $collector->insert('pve_storages', [
                'id' => $storageId,
                'connection_id' => $connectionId,
                'cluster_id' => $clusterId,
                'storage_name' => 'collector-grant-proof',
                'storage_type' => 'pbs',
                'supports_backup' => 1,
                'disabled' => 0,
                'content_json' => '["backup"]',
                'shared' => 0,
                'inventory_state' => 'active',
                'first_seen_run_id' => $runId,
                'last_seen_run_id' => $runId,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]);
            self::assertSame(1, $collector->update('pve_storages', ['disabled' => 1], ['id' => $storageId]));
            $collector->insert('pve_node_storage_state', [
                'connection_id' => $connectionId,
                'cluster_id' => $clusterId,
                'node_id' => $nodeId,
                'storage_id' => $storageId,
                'enabled' => 1,
                'active' => 0,
                'shared' => 0,
                'capacity_status' => 'unavailable',
                'observed_at' => self::NOW,
                'sync_run_id' => $runId,
            ]);
            $collector->insert('pve_storage_pbs_mappings', [
                'storage_id' => $storageId,
                'connection_id' => $connectionId,
                'cluster_id' => $clusterId,
                'server' => 'pbs.example.test',
                'port' => 8007,
                'datastore' => 'primary',
                'namespace' => null,
                'observed_at' => self::NOW,
                'sync_run_id' => $runId,
            ]);
            self::assertSame(1, $collector->delete('pve_node_storage_state', ['storage_id' => $storageId]));
            self::assertSame(1, $collector->delete('pve_storage_pbs_mappings', ['storage_id' => $storageId]));
            $this->assertDenied(static fn () => $collector->delete('pve_storages', ['id' => $storageId]));
            $collector->rollBack();
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

            $this->assertDenied(
                static fn () => $collector->fetchAllAssociative('SELECT * FROM proxmox_credentials'),
            );
            $this->assertDenied(static fn () => $collector->executeStatement(
                'UPDATE proxmox_connections SET enabled = 0 WHERE id = :connection_id',
                ['connection_id' => $connectionId],
            ));
            $this->assertDenied(static fn () => $collector->executeStatement(
                'DELETE FROM pve_nodes WHERE connection_id = :connection_id',
                ['connection_id' => $connectionId],
            ));
        } finally {
            if ($collector->isTransactionActive()) {
                $collector->rollBack();
            }
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $collector->close();
            $this->connection()->delete('proxmox_connections', ['id' => $connectionId]);
        }
    }

    public function testCollectorHasExactPbsInventoryMutationGrants(): void
    {
        $connectionId = random_bytes(16);
        $endpointId = random_bytes(16);
        $this->insertPbsConnection($connectionId, $endpointId);
        $this->connection()->commit();

        $collector = $this->collectorConnection();
        try {
            $collector->beginTransaction();
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $serverId = random_bytes(16);
            $datastoreId = random_bytes(16);
            $runId = random_bytes(16);
            $collector->insert('pbs_servers', [
                'id' => $serverId,
                'connection_id' => $connectionId,
                'node_name' => 'pbs-grant-proof',
                'version_major' => 4,
                'version_minor' => 2,
                'version_patch' => 0,
                'version_text' => '4.2.0',
                'release_text' => '1',
                'repo_id' => 'repo',
                'first_seen_run_id' => $runId,
                'last_seen_run_id' => $runId,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]);
            self::assertSame(1, $collector->update('pbs_servers', ['version_patch' => 1], ['id' => $serverId]));
            $collector->insert('pbs_server_status', [
                'server_id' => $serverId,
                'connection_id' => $connectionId,
                'uptime_seconds' => 1,
                'memory_total_bytes' => 100,
                'memory_used_bytes' => 10,
                'root_total_bytes' => 100,
                'root_used_bytes' => 10,
                'root_available_bytes' => 90,
                'observed_at' => self::NOW,
                'sync_run_id' => $runId,
            ]);
            self::assertSame(1, $collector->update(
                'pbs_server_status',
                ['uptime_seconds' => 2],
                ['server_id' => $serverId],
            ));
            $collector->insert('pbs_datastores', [
                'id' => $datastoreId,
                'connection_id' => $connectionId,
                'server_id' => $serverId,
                'datastore_name' => 'grant-proof',
                'backend_type' => 's3',
                'mount_status' => 'mounted',
                'maintenance_mode' => null,
                'allows_backup_writes' => 1,
                'inventory_state' => 'active',
                'first_seen_run_id' => $runId,
                'last_seen_run_id' => $runId,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
                'archived_at' => null,
            ]);
            self::assertSame(1, $collector->update(
                'pbs_datastores',
                ['allows_backup_writes' => 0, 'maintenance_mode' => 'read-only'],
                ['id' => $datastoreId],
            ));
            $collector->insert('pbs_datastore_capacity_state', [
                'datastore_id' => $datastoreId,
                'connection_id' => $connectionId,
                'server_id' => $serverId,
                'backend_type' => 's3',
                'semantics' => 'local_cache',
                'total_bytes' => 100,
                'used_bytes' => 10,
                'available_bytes' => 90,
                'observed_at' => self::NOW,
                'sync_run_id' => $runId,
            ]);
            self::assertSame(1, $collector->update(
                'pbs_datastore_capacity_state',
                ['used_bytes' => 20],
                ['datastore_id' => $datastoreId],
            ));

            $this->assertDenied(static fn () => $collector->delete('pbs_server_status', ['server_id' => $serverId]));
            $this->assertDenied(static fn () => $collector->delete('pbs_datastores', ['id' => $datastoreId]));
            $this->assertDenied(static fn () => $collector->delete('pbs_servers', ['id' => $serverId]));
            self::assertSame(1, $collector->delete(
                'pbs_datastore_capacity_state',
                ['datastore_id' => $datastoreId],
            ));
            $collector->rollBack();
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        } finally {
            if ($collector->isTransactionActive()) {
                $collector->rollBack();
            }
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $collector->close();
            $this->connection()->delete('proxmox_connections', ['id' => $connectionId]);
        }
    }

    public function testCollectorHasExactCapabilitySnapshotGrants(): void
    {
        $connectionId = random_bytes(16);
        $endpointId = random_bytes(16);
        $this->insertPbsConnection($connectionId, $endpointId);
        $this->connection()->commit();

        $collector = $this->collectorConnection();
        try {
            $snapshotId = random_bytes(16);
            $collector->beginTransaction();
            $collector->insert('proxmox_capability_snapshots', [
                'id' => $snapshotId,
                'connection_id' => $connectionId,
                'endpoint_id' => $endpointId,
                'product' => 'pbs',
                'version_major' => 4,
                'version_minor' => 2,
                'version_patch' => 0,
                'release_name' => '1',
                'raw_version' => '4.2.0',
                'profile_version' => 1,
                'capabilities_json' => '{"instanceIdentitySupported":true,"versionContract":"pbs_4"}',
                'snapshot_hash' => random_bytes(32),
                'first_observed_at' => self::NOW,
                'last_observed_at' => self::NOW,
            ]);
            self::assertSame(1, $collector->update(
                'proxmox_capability_snapshots',
                ['last_observed_at' => '2026-07-11 10:00:01.000000'],
                ['id' => $snapshotId],
            ));
            self::assertSame(
                'pbs_4',
                $collector->fetchOne(
                    "SELECT JSON_UNQUOTE(JSON_EXTRACT(capabilities_json, '$.versionContract')) FROM proxmox_capability_snapshots WHERE id = :id",
                    ['id' => $snapshotId],
                ),
            );
            $this->assertDenied(static fn () => $collector->delete(
                'proxmox_capability_snapshots',
                ['id' => $snapshotId],
            ));
            $collector->rollBack();
        } finally {
            if ($collector->isTransactionActive()) {
                $collector->rollBack();
            }
            $collector->close();
            $this->connection()->delete('proxmox_connections', ['id' => $connectionId]);
        }
    }

    public function testCollectorHasMonitoringWriteGrantsButNoDeleteOrScopeUpdateGrant(): void
    {
        $connectionId = random_bytes(16);
        $endpointId = random_bytes(16);
        $this->insertPbsConnection($connectionId, $endpointId);
        $this->connection()->commit();

        $collector = $this->collectorConnection();
        try {
            $collector->beginTransaction();
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $runId = random_bytes(16);
            $scopeId = random_bytes(16);
            $serverId = random_bytes(16);
            $jobId = random_bytes(16);
            $taskId = random_bytes(16);
            $pveJobId = random_bytes(16);
            $pveTaskId = random_bytes(16);
            $rawUpid = 'UPID:pbs-a:0000002A:000F4240:AAAAAAAAAAAAAAAA:68D927C0:backup:store_a:root@pam:';
            $pveRawUpid = 'UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:observer@pve:';
            $collector->insert('proxmox_monitoring_runs', [
                'id' => $runId,
                'connection_id' => $connectionId,
                'parent_sync_run_id' => random_bytes(16),
                'product' => 'pbs',
                'binding_kind' => 'pbs_legacy_node',
                'binding_value' => 'pbs-a',
                'binding_legacy_endpoint_id' => $endpointId,
                'monitoring_kind' => 'observed_tasks',
                'expected_connection_revision' => 1,
                'endpoint_id' => $endpointId,
                'cycle_token' => random_bytes(16),
                'collector_fencing_token' => 1,
                'status' => 'running',
                'started_at' => self::NOW,
                'heartbeat_at' => self::NOW,
            ]);
            self::assertSame(1, $collector->update(
                'proxmox_monitoring_runs', ['heartbeat_at' => '2026-07-11 10:00:01.000000'], ['id' => $runId],
            ));
            $collector->insert('proxmox_monitoring_scope_results', [
                'id' => $scopeId,
                'connection_id' => $connectionId,
                'monitoring_run_id' => $runId,
                'scope_type' => 'pbs_tasks_running',
                'scope_key' => 'pbs-a',
                'source_kind' => 'running',
                'filter_value' => 'backup',
                'status' => 'complete',
                'pages_read' => 1,
                'rows_read' => 0,
                'items_seen' => 0,
                'truncated' => 0,
                'history_gap' => 0,
                'observed_at' => self::NOW,
            ]);
            $collector->insert('proxmox_monitoring_cursors', [
                'connection_id' => $connectionId,
                'product' => 'pbs',
                'cursor_kind' => 'pbs_tasks_window',
                'scope_key' => 'pbs-a',
                'completed_until' => self::NOW,
                'last_complete_run_id' => $runId,
                'updated_at' => self::NOW,
            ]);
            self::assertSame(1, $collector->update(
                'proxmox_monitoring_cursors', ['updated_at' => '2026-07-11 10:00:01.000000'],
                ['connection_id' => $connectionId, 'cursor_kind' => 'pbs_tasks_window', 'scope_key' => 'pbs-a'],
            ));
            $collector->insert('pbs_external_jobs', [
                'id' => $jobId,
                'connection_id' => $connectionId,
                'server_id' => $serverId,
                'job_kind' => 'prune',
                'external_job_id' => 'prune_a',
                'enabled' => 1,
                'details_json' => '{}',
                'config_hash' => hash('sha256', '{}', true),
                'first_seen_run_id' => $runId,
                'last_seen_run_id' => $runId,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]);
            self::assertSame(1, $collector->update('pbs_external_jobs', ['enabled' => 0], ['id' => $jobId]));
            $collector->insert('pbs_observed_tasks', [
                'id' => $taskId,
                'connection_id' => $connectionId,
                'server_id' => $serverId,
                'upid_hash' => hash('sha256', $rawUpid, true),
                'upid_raw' => $rawUpid,
                'upid_node_name' => 'pbs-a',
                'reported_node_name' => 'localhost',
                'pid_hex' => '0000002a',
                'pstart_hex' => '000f4240',
                'task_id_hex' => 'aaaaaaaaaaaaaaaa',
                'starttime_hex' => '68d927c0',
                'worker_type' => 'backup',
                'worker_id' => 'store_a',
                'auth_id' => 'root@pam',
                'seen_running' => 1,
                'seen_history' => 0,
                'lifecycle' => 'running',
                'remote_status' => null,
                'started_at' => self::NOW,
                'finished_at' => null,
                'first_seen_run_id' => $runId,
                'last_seen_run_id' => $runId,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]);
            self::assertSame(1, $collector->update(
                'pbs_observed_tasks', ['reported_node_name' => 'pbs-a'], ['id' => $taskId],
            ));
            $collector->insert('pve_external_backup_jobs', [
                'id' => $pveJobId,
                'connection_id' => $connectionId,
                'external_job_id' => 'backup_a',
                'enabled' => 1,
                'config_hash' => hash('sha256', 'backup_a', true),
                'first_seen_run_id' => $runId,
                'last_seen_run_id' => $runId,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]);
            self::assertSame(1, $collector->update(
                'pve_external_backup_jobs', ['enabled' => 0], ['id' => $pveJobId],
            ));
            $collector->insert('pve_observed_backup_tasks', [
                'id' => $pveTaskId,
                'connection_id' => $connectionId,
                'upid_hash' => hash('sha256', $pveRawUpid, true),
                'upid_raw' => $pveRawUpid,
                'node_name' => 'pve-a',
                'pid_hex' => '0000002a',
                'pstart_hex' => '000f4240',
                'starttime_hex' => '67000000',
                'task_id' => '101',
                'auth_id' => 'observer@pve',
                'seen_active' => 1,
                'seen_archive' => 0,
                'lifecycle' => 'running',
                'remote_status' => null,
                'started_at' => self::NOW,
                'finished_at' => null,
                'first_seen_run_id' => $runId,
                'last_seen_run_id' => $runId,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]);
            self::assertSame(1, $collector->update(
                'pve_observed_backup_tasks', ['seen_archive' => 1], ['id' => $pveTaskId],
            ));

            $this->assertDenied(static fn () => $collector->update(
                'proxmox_monitoring_scope_results', ['items_seen' => 1], ['id' => $scopeId],
            ));
            foreach ([
                ['proxmox_monitoring_runs', ['id' => $runId]],
                ['proxmox_monitoring_scope_results', ['id' => $scopeId]],
                ['proxmox_monitoring_cursors', ['connection_id' => $connectionId]],
                ['pve_external_backup_jobs', ['id' => $pveJobId]],
                ['pve_observed_backup_tasks', ['id' => $pveTaskId]],
                ['pbs_external_jobs', ['id' => $jobId]],
                ['pbs_observed_tasks', ['id' => $taskId]],
            ] as [$table, $criteria]) {
                $this->assertDenied(static fn () => $collector->delete($table, $criteria));
            }
            $collector->rollBack();
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        } finally {
            if ($collector->isTransactionActive()) {
                $collector->rollBack();
            }
            $collector->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $collector->close();
            $this->connection()->delete('proxmox_connections', ['id' => $connectionId]);
        }
    }

    private function seedBothCredentialPurposes(string $connectionId, string $endpointId): void
    {
        $this->connection()->insert('proxmox_connections', [
            'id' => $connectionId,
            'display_name' => 'Collector privilege test '.bin2hex(random_bytes(4)),
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $endpointId,
            'connection_id' => $connectionId,
            'host' => 'collector-pve.example.test',
            'port' => 8006,
            'priority' => 1,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach (['collector', 'backup'] as $purpose) {
            $this->connection()->insert('proxmox_credentials', [
                'id' => random_bytes(16),
                'connection_id' => $connectionId,
                'purpose' => $purpose,
                'auth_scheme' => 'api_token',
                'principal' => $purpose.'@pve',
                'token_name' => 'inventory',
                'secret_envelope' => 'opaque-encrypted-envelope-'.$purpose,
                'envelope_version' => 1,
                'key_id' => 'key_1',
                'revision' => 1,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
    }

    private function insertPbsConnection(string $connectionId, string $endpointId): void
    {
        $this->connection()->insert('proxmox_connections', [
            'id' => $connectionId,
            'display_name' => 'PBS collector privilege '.bin2hex(random_bytes(4)),
            'product' => 'pbs',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $endpointId,
            'connection_id' => $connectionId,
            'host' => 'collector-pbs.example.test',
            'port' => 8007,
            'priority' => 1,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function collectorConnection(): Connection
    {
        $password = file_get_contents('/run/secrets/mariadb_collector_password');
        self::assertIsString($password);

        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => 'hoddmimir_collector',
            'password' => trim($password),
        ]));
    }

    /** @param callable(): mixed $operation */
    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('The collector database user exceeded its production grants.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
