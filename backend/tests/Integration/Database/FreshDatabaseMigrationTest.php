<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Domain\Backup\BackupRequestState;

final class FreshDatabaseMigrationTest extends DatabaseTestCase
{
    private const array EXPECTED_TABLES = [
        'audit_events',
        'backup_capacity_reservations',
        'backup_node_slots',
        'backup_notification_outbox',
        'backup_operation_commands',
        'backup_policies',
        'backup_policy_assignments',
        'backup_policy_guest_overrides',
        'backup_problem_states',
        'backup_request_events',
        'backup_requests',
        'backup_run_events',
        'backup_run_log_entries',
        'backup_runs',
        'backup_target_allowed_nodes',
        'backup_target_slots',
        'backup_targets',
        'collector_cycles',
        'collector_schedule',
        'configuration_command_idempotency',
        'doctrine_migration_versions',
        'executor_evidence_refresh_projection_stage',
        'executor_evidence_refresh_state',
        'executor_evidence_refresh_subject_stage',
        'executor_permission_evidence',
        'guest_backup_state',
        'guest_placements',
        'guest_write_counter_resets',
        'guest_write_states',
        'guests',
        'inventory_sync_endpoint_attempts',
        'inventory_sync_failures',
        'inventory_sync_runs',
        'inventory_sync_scope_results',
        'login_attempts',
        'login_global_throttle',
        'login_ip_attempts',
        'pbs_backup_groups',
        'pbs_content_runs',
        'pbs_content_scope_results',
        'pbs_datastore_capacity_state',
        'pbs_datastores',
        'pbs_external_jobs',
        'pbs_namespaces',
        'pbs_observed_tasks',
        'pbs_server_status',
        'pbs_servers',
        'pbs_snapshots',
        'permissions',
        'proxmox_capability_snapshots',
        'proxmox_connection_endpoints',
        'proxmox_connection_onboarding_state',
        'proxmox_connections',
        'proxmox_credentials',
        'proxmox_endpoint_onboarding_evidence',
        'proxmox_installation_bindings',
        'proxmox_monitoring_cursors',
        'proxmox_monitoring_runs',
        'proxmox_monitoring_scope_results',
        'proxmox_onboarding_commands',
        'pve_clusters',
        'pve_external_backup_jobs',
        'pve_node_storage_state',
        'pve_nodes',
        'pve_observed_backup_tasks',
        'pve_storage_pbs_mappings',
        'pve_storages',
        'role_permissions',
        'roles',
        'scheduler_decision_gates',
        'scheduler_decisions',
        'scheduler_evaluation_runs',
        'security_command_idempotency',
        'user_roles',
        'users',
        'web_sessions',
        'worker_heartbeats',
    ];

    public function testFreshSchemaIsInstalledOnMariaDb114(): void
    {
        $version = $this->connection()->fetchOne('SELECT VERSION()');
        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^11\.4\.[0-9]+-MariaDB/', $version);

        /** @var list<array{table_name: string, engine: string}> $tables */
        $tables = $this->connection()->fetchAllAssociative(
            <<<'SQL'
                SELECT TABLE_NAME AS table_name, ENGINE AS engine
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :database_name
                  AND TABLE_TYPE = 'BASE TABLE'
                ORDER BY BINARY TABLE_NAME
                SQL,
            ['database_name' => $this->databaseName()],
        );

        self::assertSame(
            self::EXPECTED_TABLES,
            array_column($tables, 'table_name'),
            'The fresh integration database must contain exactly the initial V2 schema.',
        );

        foreach ($tables as $table) {
            self::assertSame('InnoDB', $table['engine'], sprintf('%s must use InnoDB.', $table['table_name']));
        }

        self::assertSame(
            [
                'backup_credentials',
                'backup_request_client_configurations',
                'collector_credentials',
                'credential_key_usage',
                'current_executor_permission_evidence',
                'executor_evidence_claim_catalog',
                'executor_evidence_endpoint_catalog',
                'executor_evidence_subject_catalog',
                'executor_scan_credentials',
            ],
            $this->connection()->fetchFirstColumn(
                <<<'SQL'
                    SELECT TABLE_NAME
                    FROM information_schema.VIEWS
                    WHERE TABLE_SCHEMA = DATABASE()
                    ORDER BY BINARY TABLE_NAME
                    SQL,
            ),
        );
    }

    public function testSchemaMigrationsWereAppliedExactlyOnce(): void
    {
        $versions = $this->connection()->fetchFirstColumn(
            'SELECT version FROM doctrine_migration_versions ORDER BY version',
        );

        self::assertCount(32, $versions);
        self::assertIsString($versions[0]);
        self::assertStringEndsWith('Version20260710000100', $versions[0]);
        self::assertIsString($versions[1]);
        self::assertStringEndsWith('Version20260711000100', $versions[1]);
        self::assertIsString($versions[2]);
        self::assertStringEndsWith('Version20260711000200', $versions[2]);
        self::assertIsString($versions[3]);
        self::assertStringEndsWith('Version20260711000300', $versions[3]);
        self::assertIsString($versions[4]);
        self::assertStringEndsWith('Version20260712000100', $versions[4]);
        self::assertIsString($versions[5]);
        self::assertStringEndsWith('Version20260712000200', $versions[5]);
        self::assertIsString($versions[6]);
        self::assertStringEndsWith('Version20260712000300', $versions[6]);
        self::assertIsString($versions[7]);
        self::assertStringEndsWith('Version20260712000400', $versions[7]);
        self::assertIsString($versions[8]);
        self::assertStringEndsWith('Version20260712000500', $versions[8]);
        self::assertIsString($versions[9]);
        self::assertStringEndsWith('Version20260712000600', $versions[9]);
        self::assertIsString($versions[10]);
        self::assertStringEndsWith('Version20260712000700', $versions[10]);
        self::assertIsString($versions[11]);
        self::assertStringEndsWith('Version20260712000800', $versions[11]);
        self::assertIsString($versions[12]);
        self::assertStringEndsWith('Version20260712000850', $versions[12]);
        self::assertIsString($versions[13]);
        self::assertStringEndsWith('Version20260712000900', $versions[13]);
        self::assertIsString($versions[14]);
        self::assertStringEndsWith('Version20260712001000', $versions[14]);
        self::assertIsString($versions[15]);
        self::assertStringEndsWith('Version20260712001100', $versions[15]);
        self::assertIsString($versions[16]);
        self::assertStringEndsWith('Version20260712001200', $versions[16]);
        self::assertIsString($versions[17]);
        self::assertStringEndsWith('Version20260712001300', $versions[17]);
        self::assertIsString($versions[18]);
        self::assertStringEndsWith('Version20260712001400', $versions[18]);
        self::assertIsString($versions[19]);
        self::assertStringEndsWith('Version20260712001500', $versions[19]);
        self::assertIsString($versions[20]);
        self::assertStringEndsWith('Version20260712001600', $versions[20]);
        self::assertIsString($versions[21]);
        self::assertStringEndsWith('Version20260712001700', $versions[21]);
        self::assertIsString($versions[22]);
        self::assertStringEndsWith('Version20260712001800', $versions[22]);
        self::assertIsString($versions[23]);
        self::assertStringEndsWith('Version20260712001900', $versions[23]);
        self::assertIsString($versions[24]);
        self::assertStringEndsWith('Version20260712002000', $versions[24]);
        self::assertIsString($versions[25]);
        self::assertStringEndsWith('Version20260712002100', $versions[25]);
        self::assertIsString($versions[26]);
        self::assertStringEndsWith('Version20260712002200', $versions[26]);
        self::assertIsString($versions[27]);
        self::assertStringEndsWith('Version20260715000100', $versions[27]);
        self::assertIsString($versions[28]);
        self::assertStringEndsWith('Version20260715000200', $versions[28]);
        self::assertIsString($versions[29]);
        self::assertStringEndsWith('Version20260715154500', $versions[29]);
        self::assertIsString($versions[30]);
        self::assertStringEndsWith('Version20260716000100', $versions[30]);
        self::assertIsString($versions[31]);
        self::assertStringEndsWith('Version20260716000200', $versions[31]);
    }

    public function testActiveBackupRequestGuestKeyIsGeneratedAndUnique(): void
    {
        $column = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT EXTRA AS extra, GENERATION_EXPRESSION AS expression
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_requests' AND COLUMN_NAME = 'active_guest_id'
            SQL);
        self::assertIsArray($column);
        self::assertIsString($column['extra']);
        self::assertIsString($column['expression']);
        self::assertStringContainsString('STORED GENERATED', strtoupper($column['extra']));
        $expression = strtolower($column['expression']);
        preg_match_all("/'([^']+)'/", $expression, $matches);
        $generatedActiveStates = $matches[1];
        $domainActiveStates = array_values(array_map(
            static fn (BackupRequestState $state): string => $state->value,
            array_filter(
                BackupRequestState::cases(),
                static fn (BackupRequestState $state): bool => !$state->isTerminal(),
            ),
        ));
        sort($generatedActiveStates);
        sort($domainActiveStates);
        self::assertSame($domainActiveStates, $generatedActiveStates);

        $unique = $this->connection()->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_requests'
              AND INDEX_NAME = 'uq_backup_requests_active_guest' AND NON_UNIQUE = 0
            SQL);
        self::assertTrue(is_int($unique) || is_string($unique));
        self::assertSame('3', (string) $unique);
    }

    public function testBackupRunLogsPreserveTheCompleteTypedPveLineBoundary(): void
    {
        $column = $this->connection()->fetchAssociative(<<<'SQL'
SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS maximum_length
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'backup_run_log_entries'
  AND COLUMN_NAME = 'content'
SQL);
        self::assertIsArray($column);
        self::assertSame('mediumtext', $column['data_type']);
        $maximumLength = $column['maximum_length'];
        if (!is_int($maximumLength) && !is_string($maximumLength)) {
            self::fail('MariaDB returned an invalid MEDIUMTEXT maximum length.');
        }
        self::assertSame('16777215', (string) $maximumLength);

        $clause = $this->connection()->fetchOne(<<<'SQL'
SELECT CHECK_CLAUSE
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME = 'chk_backup_run_logs_content'
SQL);
        self::assertIsString($clause);
        self::assertStringContainsString('octet_length(`content`)', strtolower($clause));
        self::assertStringContainsString('65536', $clause);
    }

    public function testDomainTimestampsUseMicrosecondUtcSafeStorage(): void
    {
        /** @var list<array{table_name: string, column_name: string, data_type: string, datetime_precision: int|null}> $columns */
        $columns = $this->connection()->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    TABLE_NAME AS table_name,
                    COLUMN_NAME AS column_name,
                    DATA_TYPE AS data_type,
                    DATETIME_PRECISION AS datetime_precision
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = :database_name
                  AND TABLE_NAME <> 'doctrine_migration_versions'
                  AND COLUMN_NAME LIKE '%\\_at' ESCAPE '\\'
                ORDER BY TABLE_NAME, ORDINAL_POSITION
                SQL,
            ['database_name' => $this->databaseName()],
        );

        self::assertNotSame([], $columns, 'The schema must expose its UTC timestamp columns.');

        foreach ($columns as $column) {
            self::assertSame(
                'datetime',
                $column['data_type'],
                sprintf('%s.%s must not use session-converting TIMESTAMP.', $column['table_name'], $column['column_name']),
            );
            self::assertSame(
                6,
                $column['datetime_precision'],
                sprintf('%s.%s must retain microseconds.', $column['table_name'], $column['column_name']),
            );
        }
    }

    public function testGuestBackupStateKeepsBackupSizeSeparateFromWriteBaseline(): void
    {
        $columns = $this->connection()->fetchAllAssociative(
            <<<'SQL'
                SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_backup_state'
                  AND COLUMN_NAME IN ('last_success_size_bytes', 'baseline_bytes')
                ORDER BY ORDINAL_POSITION
                SQL,
        );
        self::assertSame([
            ['name' => 'last_success_size_bytes', 'type' => 'bigint(20) unsigned', 'nullable' => 'YES'],
            ['name' => 'baseline_bytes', 'type' => 'bigint(20) unsigned', 'nullable' => 'NO'],
        ], $columns);
    }
}
