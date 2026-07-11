<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Exception;

final class SchemaConstraintTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-10 14:00:00.000000';

    public function testSchemaUsesStableExplicitConstraintNames(): void
    {
        $constraintNames = $this->connection()->fetchFirstColumn(
            <<<'SQL'
                SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = :database_name
                  AND CONSTRAINT_NAME <> 'PRIMARY'
                ORDER BY CONSTRAINT_NAME
                SQL,
            ['database_name' => $this->databaseName()],
        );

        self::assertNotSame([], $constraintNames);

        foreach ($constraintNames as $constraintName) {
            self::assertIsString($constraintName);
            self::assertMatchesRegularExpression(
                '/^(chk|fk|uq)_[a-z0-9_]+$/',
                $constraintName,
                sprintf('Constraint %s must have a stable purpose prefix.', $constraintName),
            );
        }

        foreach ([
            'chk_proxmox_connections_product',
            'chk_proxmox_endpoints_tls_mode',
            'chk_proxmox_credentials_envelope',
            'chk_collector_schedule_lease',
            'chk_collector_cycles_time',
            'chk_collector_cycles_worker_kind',
            'chk_worker_heartbeats_cycle',
            'chk_guests_template',
            'chk_inventory_sync_runs_authority',
            'chk_pve_storages_backup',
            'chk_pve_storages_shared',
            'fk_proxmox_endpoints_connection',
            'fk_guest_placements_node',
            'uq_guests_cluster_type_vmid',
            'uq_inventory_sync_runs_cycle_connection',
            'fk_inventory_sync_runs_cycle_fence',
        ] as $requiredConstraint) {
            self::assertContains($requiredConstraint, $constraintNames);
        }
    }

    public function testProductCheckConstraintRejectsUnknownProducts(): void
    {
        $this->assertConstraintRejects(
            'chk_proxmox_connections_product',
            fn () => $this->connection()->insert('proxmox_connections', [
                'id' => random_bytes(16),
                'display_name' => 'Invalid product',
                'product' => 'other',
                'enabled' => 1,
                'revision' => 1,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]),
        );
    }

    public function testEndpointForeignKeyRejectsAnUnknownConnection(): void
    {
        $this->assertConstraintRejects(
            'fk_proxmox_endpoints_connection',
            fn () => $this->connection()->insert('proxmox_connection_endpoints', [
                'id' => random_bytes(16),
                'connection_id' => random_bytes(16),
                'host' => 'pve.invalid.example',
                'port' => 8006,
                'priority' => 100,
                'enabled' => 1,
                'tls_mode' => 'system_ca',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]),
        );
    }

    public function testDisplayNameUniqueConstraintRejectsDuplicates(): void
    {
        $this->insertConnection(random_bytes(16), 'Duplicate connection');

        $this->assertConstraintRejects(
            'uq_proxmox_connections_display_name',
            fn () => $this->insertConnection(random_bytes(16), 'Duplicate connection'),
        );
    }

    public function testTlsConstraintRequiresExactlyOneTrustModePayload(): void
    {
        $connectionId = random_bytes(16);
        $this->insertConnection($connectionId, 'TLS validation');

        $this->assertConstraintRejects(
            'chk_proxmox_endpoints_tls_mode',
            fn () => $this->connection()->insert('proxmox_connection_endpoints', [
                'id' => random_bytes(16),
                'connection_id' => $connectionId,
                'host' => 'pve.example.test',
                'port' => 8006,
                'priority' => 100,
                'enabled' => 1,
                'tls_mode' => 'system_ca',
                'custom_ca_pem' => 'must not coexist with system_ca',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]),
        );

        $this->assertConstraintRejects(
            'chk_proxmox_endpoints_tls_mode',
            fn () => $this->connection()->insert('proxmox_connection_endpoints', [
                'id' => random_bytes(16),
                'connection_id' => $connectionId,
                'host' => 'pve-large-ca.example.test',
                'port' => 8006,
                'priority' => 100,
                'enabled' => 1,
                'tls_mode' => 'custom_ca',
                'custom_ca_pem' => str_repeat('x', 262_145),
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]),
        );
    }

    public function testCredentialEnvelopeConstraintRejectsEmptyCiphertext(): void
    {
        $connectionId = random_bytes(16);
        $this->insertConnection($connectionId, 'Credential validation');

        $this->assertConstraintRejects(
            'chk_proxmox_credentials_envelope',
            fn () => $this->connection()->insert('proxmox_credentials', [
                'id' => random_bytes(16),
                'connection_id' => $connectionId,
                'purpose' => 'collector',
                'auth_scheme' => 'api_token',
                'principal' => 'collector@pve',
                'token_name' => 'hoddmimir',
                'secret_envelope' => '',
                'envelope_version' => 1,
                'key_id' => 'test-key',
                'revision' => 1,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]),
        );
    }

    public function testCollectorLeaseConstraintRejectsPartialLeaseState(): void
    {
        $this->assertConstraintRejects(
            'chk_collector_schedule_lease',
            fn () => $this->connection()->insert('collector_schedule', [
                'schedule_name' => 'inventory',
                'grid_started_at' => self::NOW,
                'interval_seconds' => 120,
                'next_scan_at' => '2026-07-10 14:02:00.000000',
                'lease_owner' => random_bytes(16),
                'lease_fencing_token' => 1,
                'updated_at' => self::NOW,
            ]),
        );
    }

    public function testSuccessfulSyncMustBeAuthoritative(): void
    {
        $connectionId = random_bytes(16);
        $this->insertConnection($connectionId, 'Sync authority validation');
        $cycle = $this->insertTerminalCycle();

        $this->assertConstraintRejects(
            'chk_inventory_sync_runs_authority',
            fn () => $this->connection()->insert('inventory_sync_runs', [
                'id' => random_bytes(16),
                'cycle_token' => $cycle['token'],
                'collector_fencing_token' => $cycle['fence'],
                'connection_id' => $connectionId,
                'expected_connection_revision' => 1,
                'status' => 'succeeded',
                'authoritative' => 0,
                'started_at' => self::NOW,
                'heartbeat_at' => self::NOW,
                'finished_at' => '2026-07-10 14:00:01.000000',
            ]),
        );
    }

    public function testInventorySeenRunsMustBelongToTheSameConnection(): void
    {
        $connectionA = random_bytes(16);
        $connectionB = random_bytes(16);
        $runA = random_bytes(16);
        $runB = random_bytes(16);
        $clusterA = random_bytes(16);

        $this->insertConnection($connectionA, 'Connection A');
        $this->insertConnection($connectionB, 'Connection B');
        $this->insertSuccessfulRun($runA, $connectionA);
        $this->insertSuccessfulRun($runB, $connectionB);

        $this->assertConstraintRejects(
            'fk_pve_clusters_first_seen',
            fn () => $this->insertCluster($clusterA, $connectionA, $runB, $runA),
        );
        $this->assertConstraintRejects(
            'fk_pve_clusters_last_seen',
            fn () => $this->insertCluster($clusterA, $connectionA, $runA, $runB),
        );

        $this->insertCluster($clusterA, $connectionA, $runA, $runA);

        $this->assertConstraintRejects(
            'fk_pve_nodes_first_seen',
            fn () => $this->insertNode(random_bytes(16), $connectionA, $clusterA, $runB, $runA),
        );
        $this->assertConstraintRejects(
            'fk_pve_nodes_last_seen',
            fn () => $this->insertNode(random_bytes(16), $connectionA, $clusterA, $runA, $runB),
        );
    }

    public function testCurrentInventoryStateRunMustBelongToTheObjectsConnection(): void
    {
        $connectionA = random_bytes(16);
        $connectionB = random_bytes(16);
        $runA = random_bytes(16);
        $runB = random_bytes(16);
        $clusterA = random_bytes(16);
        $nodeA = random_bytes(16);
        $guestA = random_bytes(16);
        $storageA = random_bytes(16);

        $this->insertConnection($connectionA, 'Placement connection A');
        $this->insertConnection($connectionB, 'Placement connection B');
        $this->insertSuccessfulRun($runA, $connectionA);
        $this->insertSuccessfulRun($runB, $connectionB);
        $this->insertCluster($clusterA, $connectionA, $runA, $runA);
        $this->insertNode($nodeA, $connectionA, $clusterA, $runA, $runA);
        $this->insertGuest($guestA, $connectionA, $clusterA, $runA);
        $this->insertStorage($storageA, $connectionA, $clusterA, $runA);

        $this->assertConstraintRejects(
            'fk_guest_placements_run',
            fn () => $this->connection()->insert('guest_placements', [
                'guest_id' => $guestA,
                'connection_id' => $connectionA,
                'cluster_id' => $clusterA,
                'node_id' => $nodeA,
                'observed_at' => self::NOW,
                'sync_run_id' => $runB,
            ]),
        );
        $this->assertConstraintRejects(
            'fk_pve_node_storage_run',
            fn () => $this->connection()->insert('pve_node_storage_state', [
                'connection_id' => $connectionA,
                'cluster_id' => $clusterA,
                'node_id' => $nodeA,
                'storage_id' => $storageA,
                'enabled' => 1,
                'active' => 1,
                'observed_at' => self::NOW,
                'sync_run_id' => $runB,
            ]),
        );
    }

    public function testGuestMetadataMayBeUnknownButTemplateStatusRemainsTriState(): void
    {
        $connectionId = random_bytes(16);
        $runId = random_bytes(16);
        $clusterId = random_bytes(16);
        $unknownGuestId = random_bytes(16);
        $omittedMetadataGuestId = random_bytes(16);

        $this->insertConnection($connectionId, 'Unknown guest metadata');
        $this->insertSuccessfulRun($runId, $connectionId);
        $this->insertCluster($clusterId, $connectionId, $runId, $runId);

        $this->connection()->insert('guests', [
            'id' => $unknownGuestId,
            'connection_id' => $connectionId,
            'cluster_id' => $clusterId,
            'guest_type' => 'qemu',
            'vmid' => 100,
            'name' => null,
            'is_template' => null,
            'inventory_state' => 'active',
            'first_seen_run_id' => $runId,
            'last_seen_run_id' => $runId,
            'first_seen_at' => self::NOW,
            'last_seen_at' => self::NOW,
        ]);

        self::assertSame(
            ['name' => null, 'is_template' => null],
            $this->connection()->fetchAssociative(
                'SELECT name, is_template FROM guests WHERE id = :id',
                ['id' => $unknownGuestId],
            ),
        );

        $this->connection()->insert('guests', [
            'id' => $omittedMetadataGuestId,
            'connection_id' => $connectionId,
            'cluster_id' => $clusterId,
            'guest_type' => 'lxc',
            'vmid' => 101,
            'inventory_state' => 'active',
            'first_seen_run_id' => $runId,
            'last_seen_run_id' => $runId,
            'first_seen_at' => self::NOW,
            'last_seen_at' => self::NOW,
        ]);

        self::assertSame(
            ['name' => null, 'is_template' => null],
            $this->connection()->fetchAssociative(
                'SELECT name, is_template FROM guests WHERE id = :id',
                ['id' => $omittedMetadataGuestId],
            ),
        );

        $this->assertConstraintRejects(
            'chk_guests_template',
            fn () => $this->connection()->insert('guests', [
                'id' => random_bytes(16),
                'connection_id' => $connectionId,
                'cluster_id' => $clusterId,
                'guest_type' => 'qemu',
                'vmid' => 102,
                'name' => null,
                'is_template' => 2,
                'inventory_state' => 'active',
                'first_seen_run_id' => $runId,
                'last_seen_run_id' => $runId,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]),
        );
    }

    public function testStorageMetadataMustBeExplicitAndBoolean(): void
    {
        $connectionId = random_bytes(16);
        $runId = random_bytes(16);
        $clusterId = random_bytes(16);

        $this->insertConnection($connectionId, 'Storage metadata validation');
        $this->insertSuccessfulRun($runId, $connectionId);
        $this->insertCluster($clusterId, $connectionId, $runId, $runId);

        $storageRow = static fn (string $storageName, int $supportsBackup, int $shared): array => [
            'id' => random_bytes(16),
            'connection_id' => $connectionId,
            'cluster_id' => $clusterId,
            'storage_name' => $storageName,
            'storage_type' => 'dir',
            'supports_backup' => $supportsBackup,
            'shared' => $shared,
            'inventory_state' => 'active',
            'first_seen_run_id' => $runId,
            'last_seen_run_id' => $runId,
        ];

        $this->connection()->insert('pve_storages', $storageRow('explicit-zero-one', 0, 1));
        $this->connection()->insert('pve_storages', $storageRow('explicit-one-zero', 1, 0));

        $storedRows = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM pve_storages WHERE cluster_id = :cluster_id',
            ['cluster_id' => $clusterId],
        );
        if (!is_int($storedRows) && !(is_string($storedRows) && ctype_digit($storedRows))) {
            self::fail('The storage row count has an unexpected DBAL result type.');
        }
        self::assertSame(2, (int) $storedRows);

        $missingSupportsBackup = $storageRow('missing-supports-backup', 0, 0);
        unset($missingSupportsBackup['supports_backup']);

        $this->assertRequiredFieldRejects(
            'supports_backup',
            fn () => $this->connection()->insert('pve_storages', $missingSupportsBackup),
        );

        $missingShared = $storageRow('missing-shared', 0, 0);
        unset($missingShared['shared']);

        $this->assertRequiredFieldRejects(
            'shared',
            fn () => $this->connection()->insert('pve_storages', $missingShared),
        );

        $this->assertConstraintRejects(
            'chk_pve_storages_backup',
            fn () => $this->connection()->insert('pve_storages', $storageRow('invalid-supports-backup', 2, 0)),
        );
        $this->assertConstraintRejects(
            'chk_pve_storages_shared',
            fn () => $this->connection()->insert('pve_storages', $storageRow('invalid-shared', 0, 2)),
        );
    }

    /** @param callable(): mixed $operation */
    private function assertConstraintRejects(string $constraintName, callable $operation): void
    {
        try {
            $operation();
            self::fail(sprintf('Constraint %s should have rejected the row.', $constraintName));
        } catch (Exception $exception) {
            self::assertStringContainsString($constraintName, $exception->getMessage());
        }
    }

    /** @param callable(): mixed $operation */
    private function assertRequiredFieldRejects(string $fieldName, callable $operation): void
    {
        try {
            $operation();
            self::fail(sprintf('Required field %s should not have a database default.', $fieldName));
        } catch (Exception $exception) {
            self::assertStringContainsString($fieldName, $exception->getMessage());
        }
    }

    private function insertConnection(string $id, string $displayName): void
    {
        $this->connection()->insert('proxmox_connections', [
            'id' => $id,
            'display_name' => $displayName,
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function insertSuccessfulRun(string $id, string $connectionId): void
    {
        $cycle = $this->insertTerminalCycle();
        $this->connection()->insert('inventory_sync_runs', [
            'id' => $id,
            'cycle_token' => $cycle['token'],
            'collector_fencing_token' => $cycle['fence'],
            'connection_id' => $connectionId,
            'expected_connection_revision' => 1,
            'status' => 'succeeded',
            'authoritative' => 1,
            'started_at' => self::NOW,
            'heartbeat_at' => self::NOW,
            'finished_at' => '2026-07-10 14:00:01.000000',
        ]);
    }

    /** @return array{token: string, fence: int} */
    private function insertTerminalCycle(): array
    {
        $cycleToken = random_bytes(16);
        $workerId = random_bytes(16);
        $fence = random_int(1, PHP_INT_MAX);
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT IGNORE INTO collector_schedule (
                    schedule_name, grid_started_at, interval_seconds, next_scan_at,
                    lease_fencing_token, updated_at
                ) VALUES ('inventory', :now, 120, :now, 0, :now)
                SQL,
            ['now' => self::NOW],
        );
        $this->connection()->insert('worker_heartbeats', [
            'worker_instance_id' => $workerId,
            'worker_kind' => 'collector',
            'status' => 'ready',
            'started_at' => self::NOW,
            'heartbeat_at' => self::NOW,
            'expires_at' => '2026-07-10 14:05:00.000000',
            'build_version' => 'integration-test',
        ]);
        $this->connection()->insert('collector_cycles', [
            'cycle_token' => $cycleToken,
            'schedule_name' => 'inventory',
            'worker_instance_id' => $workerId,
            'worker_kind' => 'collector',
            'fencing_token' => $fence,
            'scheduled_for' => self::NOW,
            'started_at' => self::NOW,
            'heartbeat_at' => self::NOW,
            'finished_at' => '2026-07-10 14:00:01.000000',
            'duration_ms' => 1000,
            'status' => 'succeeded',
        ]);

        return ['token' => $cycleToken, 'fence' => $fence];
    }

    private function insertCluster(
        string $id,
        string $connectionId,
        string $firstSeenRunId,
        string $lastSeenRunId,
    ): void {
        $this->connection()->insert('pve_clusters', [
            'id' => $id,
            'connection_id' => $connectionId,
            'external_name' => 'cluster-a',
            'topology' => 'clustered',
            'inventory_state' => 'active',
            'first_seen_run_id' => $firstSeenRunId,
            'last_seen_run_id' => $lastSeenRunId,
            'first_seen_at' => self::NOW,
            'last_seen_at' => self::NOW,
        ]);
    }

    private function insertNode(
        string $id,
        string $connectionId,
        string $clusterId,
        string $firstSeenRunId,
        string $lastSeenRunId,
    ): void {
        $this->connection()->insert('pve_nodes', [
            'id' => $id,
            'connection_id' => $connectionId,
            'cluster_id' => $clusterId,
            'node_name' => 'node-'.bin2hex($id),
            'api_status' => 'online',
            'inventory_state' => 'active',
            'first_seen_run_id' => $firstSeenRunId,
            'last_seen_run_id' => $lastSeenRunId,
            'first_seen_at' => self::NOW,
            'last_seen_at' => self::NOW,
        ]);
    }

    private function insertGuest(string $id, string $connectionId, string $clusterId, string $runId): void
    {
        $this->connection()->insert('guests', [
            'id' => $id,
            'connection_id' => $connectionId,
            'cluster_id' => $clusterId,
            'guest_type' => 'qemu',
            'vmid' => 100,
            'name' => 'guest-a',
            'is_template' => 0,
            'inventory_state' => 'active',
            'first_seen_run_id' => $runId,
            'last_seen_run_id' => $runId,
            'first_seen_at' => self::NOW,
            'last_seen_at' => self::NOW,
        ]);
    }

    private function insertStorage(string $id, string $connectionId, string $clusterId, string $runId): void
    {
        $this->connection()->insert('pve_storages', [
            'id' => $id,
            'connection_id' => $connectionId,
            'cluster_id' => $clusterId,
            'storage_name' => 'storage-a',
            'storage_type' => 'dir',
            'supports_backup' => 1,
            'shared' => 0,
            'inventory_state' => 'active',
            'first_seen_run_id' => $runId,
            'last_seen_run_id' => $runId,
        ]);
    }
}
