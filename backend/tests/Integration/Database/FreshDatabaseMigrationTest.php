<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

final class FreshDatabaseMigrationTest extends DatabaseTestCase
{
    private const array EXPECTED_TABLES = [
        'collector_cycles',
        'collector_schedule',
        'doctrine_migration_versions',
        'guest_placements',
        'guests',
        'inventory_sync_endpoint_attempts',
        'inventory_sync_failures',
        'inventory_sync_runs',
        'inventory_sync_scope_results',
        'proxmox_capability_snapshots',
        'proxmox_connection_endpoints',
        'proxmox_connections',
        'proxmox_credentials',
        'proxmox_installation_bindings',
        'pve_clusters',
        'pve_node_storage_state',
        'pve_nodes',
        'pve_storages',
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
            ['collector_credentials', 'credential_key_usage'],
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

    public function testInitialMigrationWasAppliedExactlyOnce(): void
    {
        $versions = $this->connection()->fetchFirstColumn(
            'SELECT version FROM doctrine_migration_versions ORDER BY version',
        );

        self::assertCount(1, $versions);
        self::assertIsString($versions[0]);
        self::assertStringEndsWith('Version20260710000100', $versions[0]);
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
}
