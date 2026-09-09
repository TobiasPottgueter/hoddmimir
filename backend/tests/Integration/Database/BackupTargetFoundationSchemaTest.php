<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Domain\Shared\UInt64Decimal;
use Doctrine\DBAL\Exception;

final class BackupTargetFoundationSchemaTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-12 10:00:00.000000';

    public function testDisabledTargetAndExplicitAllowedNodePersistWithUInt64Boundary(): void
    {
        $context = $this->seedContext();
        $target = random_bytes(16);
        $this->connection()->insert('backup_targets', $this->targetRow($context, $target, [
            'minimum_free_bytes' => UInt64Decimal::MAXIMUM,
        ]));
        $this->connection()->insert('backup_target_allowed_nodes', [
            'target_id' => $target,
            'connection_id' => $context['connection'],
            'cluster_id' => $context['cluster_a'],
            'node_id' => $context['node_a'],
            'created_at' => self::NOW,
        ]);

        $row = $this->connection()->fetchAssociative(
            'SELECT status, revision, minimum_free_bytes, created_at, updated_at, disabled_at FROM backup_targets WHERE id = :id',
            ['id' => $target],
        );
        self::assertIsArray($row);
        self::assertSame('disabled', $row['status']);
        $revision = $row['revision'] ?? null;
        self::assertTrue(is_int($revision) || is_string($revision) && ctype_digit($revision));
        self::assertSame(1, (int) $revision);
        self::assertSame(UInt64Decimal::MAXIMUM, $row['minimum_free_bytes']);
        self::assertSame(self::NOW, $row['created_at']);
        self::assertSame(self::NOW, $row['updated_at']);
        self::assertSame(self::NOW, $row['disabled_at']);
        $allowedCount = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM backup_target_allowed_nodes WHERE target_id = :id',
            ['id' => $target],
        );
        self::assertTrue(is_int($allowedCount) || is_string($allowedCount) && ctype_digit($allowedCount));
        self::assertSame(1, (int) $allowedCount);
    }

    public function testTargetDraftChecksRemainFailClosed(): void
    {
        $context = $this->seedContext();
        foreach ([
            'enabled status' => ['status' => 'enabled'],
            'zero revision' => ['revision' => 0],
            'blank display name' => ['display_name' => '   '],
            'disabled before creation' => ['disabled_at' => '2026-07-12 09:59:59.000000'],
            'update before disable' => ['updated_at' => '2026-07-12 09:59:59.000000'],
        ] as $label => $override) {
            $this->assertRejected(
                fn () => $this->connection()->insert(
                    'backup_targets',
                    $this->targetRow($context, random_bytes(16), $override),
                ),
                $label,
            );
        }
    }

    public function testCompositeForeignKeysRejectCrossClusterStorageAndAllowedNodes(): void
    {
        $context = $this->seedContext();
        $target = random_bytes(16);
        $this->connection()->insert('backup_targets', $this->targetRow($context, $target));

        $this->assertRejected(fn () => $this->connection()->insert(
            'backup_targets',
            $this->targetRow($context, random_bytes(16), ['storage_id' => $context['storage_b']]),
        ), 'cross-cluster storage');

        $this->assertRejected(fn () => $this->connection()->insert('backup_target_allowed_nodes', [
            'target_id' => $target,
            'connection_id' => $context['connection'],
            'cluster_id' => $context['cluster_a'],
            'node_id' => $context['node_b'],
            'created_at' => self::NOW,
        ]), 'cross-cluster node');

        $this->assertRejected(fn () => $this->connection()->insert('backup_target_allowed_nodes', [
            'target_id' => $target,
            'connection_id' => $context['connection_b'],
            'cluster_id' => $context['cluster_b'],
            'node_id' => $context['node_b'],
            'created_at' => self::NOW,
        ]), 'target context drift');
    }

    public function testShadowDecisionTargetForeignKeyIsContextBoundWhilePolicyRemainsUnbound(): void
    {
        $context = $this->seedContext();
        $targetA = random_bytes(16);
        $targetB = random_bytes(16);
        $this->connection()->insert('backup_targets', $this->targetRow($context, $targetA));
        $this->connection()->insert('backup_targets', $this->targetRow($context, $targetB, [
            'connection_id' => $context['connection_b'],
            'cluster_id' => $context['cluster_b'],
            'storage_id' => $context['storage_b'],
            'display_name' => 'Target B',
        ]));

        $this->connection()->insert('scheduler_decisions', $this->decisionRow($context, $targetA, 1));
        $this->assertRejected(fn () => $this->connection()->insert(
            'scheduler_decisions',
            $this->decisionRow($context, $targetB, 2),
        ), 'cross-cluster shadow target');

        /** @var list<array{constraint_name: string, referenced_table_name: string}> $foreignKeys */
        $foreignKeys = $this->connection()->fetchAllAssociative(
            <<<'SQL'
                SELECT CONSTRAINT_NAME AS constraint_name, REFERENCED_TABLE_NAME AS referenced_table_name
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'scheduler_decisions'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
                GROUP BY CONSTRAINT_NAME, REFERENCED_TABLE_NAME
                ORDER BY CONSTRAINT_NAME
                SQL,
        );
        self::assertContains([
            'constraint_name' => 'fk_scheduler_decisions_target',
            'referenced_table_name' => 'backup_targets',
        ], $foreignKeys);
        self::assertContains([
            'constraint_name' => 'fk_scheduler_decisions_policy',
            'referenced_table_name' => 'backup_policies',
        ], $foreignKeys);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function targetRow(array $context, string $target, array $override = []): array
    {
        return array_replace([
            'id' => $target,
            'connection_id' => $context['connection'],
            'cluster_id' => $context['cluster_a'],
            'storage_id' => $context['storage_a'],
            'display_name' => 'Target A',
            'status' => 'disabled',
            'revision' => 1,
            'minimum_free_bytes' => null,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'disabled_at' => self::NOW,
        ], $override);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function decisionRow(array $context, string $target, int $ordinal): array
    {
        return [
            'id' => random_bytes(16),
            'evaluation_run_id' => $context['evaluation_run'],
            'decision_ordinal' => $ordinal,
            'connection_id' => $context['connection'],
            'cluster_id' => $context['cluster_a'],
            'guest_id' => $context['guest_a'],
            'node_id' => null,
            'placement_revision' => null,
            'placement_observed_at' => null,
            'outcome' => 'blocked',
            'reason' => null,
            'priority' => null,
            'policy_id' => null,
            'policy_revision' => null,
            'policy_snapshot_hash' => null,
            'target_id' => $target,
            'target_revision' => 1,
            'inventory_observed_at' => self::NOW,
            'capacity_observed_at' => null,
            'write_state_observed_at' => null,
        ];
    }

    /** @return array<string, string> */
    private function seedContext(): array
    {
        $context = [
            'connection' => random_bytes(16),
            'connection_b' => random_bytes(16),
            'run' => random_bytes(16),
            'run_b' => random_bytes(16),
            'cluster_a' => random_bytes(16),
            'cluster_b' => random_bytes(16),
            'node_a' => random_bytes(16),
            'node_b' => random_bytes(16),
            'storage_a' => random_bytes(16),
            'storage_b' => random_bytes(16),
            'guest_a' => random_bytes(16),
            'evaluation_run' => random_bytes(16),
        ];

        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([
                [$context['connection'], $context['run'], 'PVE A'],
                [$context['connection_b'], $context['run_b'], 'PVE B'],
            ] as [$connection, $run, $displayName]) {
                $this->connection()->insert('proxmox_connections', [
                    'id' => $connection, 'display_name' => $displayName, 'product' => 'pve', 'enabled' => 1,
                    'revision' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW,
                ]);
                $this->connection()->insert('inventory_sync_runs', [
                    'id' => $run, 'cycle_token' => random_bytes(16), 'collector_fencing_token' => 1,
                    'connection_id' => $connection, 'expected_connection_revision' => 1,
                    'status' => 'succeeded', 'authoritative' => 1, 'started_at' => self::NOW,
                    'heartbeat_at' => self::NOW, 'finished_at' => self::NOW, 'applied_at' => self::NOW,
                ]);
            }
            foreach ([
                [$context['cluster_a'], $context['connection'], $context['run'], 'cluster-a'],
                [$context['cluster_b'], $context['connection_b'], $context['run_b'], 'cluster-b'],
            ] as [$cluster, $connection, $run, $name]) {
                $this->connection()->insert('pve_clusters', [
                    'id' => $cluster, 'connection_id' => $connection, 'external_name' => $name,
                    'topology' => 'clustered', 'inventory_state' => 'active',
                    'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                    'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
                ]);
            }
            foreach ([
                [$context['node_a'], $context['connection'], $context['cluster_a'], $context['run'], 'node-a'],
                [$context['node_b'], $context['connection_b'], $context['cluster_b'], $context['run_b'], 'node-b'],
            ] as [$node, $connection, $cluster, $run, $name]) {
                $this->connection()->insert('pve_nodes', [
                    'id' => $node, 'connection_id' => $connection, 'cluster_id' => $cluster,
                    'node_name' => $name, 'api_status' => 'online', 'inventory_state' => 'active',
                    'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                    'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
                ]);
            }
            foreach ([
                [$context['storage_a'], $context['connection'], $context['cluster_a'], $context['run'], 'storage-a'],
                [$context['storage_b'], $context['connection_b'], $context['cluster_b'], $context['run_b'], 'storage-b'],
            ] as [$storage, $connection, $cluster, $run, $name]) {
                $this->connection()->insert('pve_storages', [
                    'id' => $storage, 'connection_id' => $connection, 'cluster_id' => $cluster,
                    'storage_name' => $name, 'storage_type' => 'dir', 'supports_backup' => 1,
                    'disabled' => 0, 'content_json' => '["backup"]', 'shared' => 1,
                    'inventory_state' => 'active', 'first_seen_run_id' => $run,
                    'last_seen_run_id' => $run, 'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
                ]);
            }
            $this->connection()->insert('guests', [
                'id' => $context['guest_a'], 'connection_id' => $context['connection'],
                'cluster_id' => $context['cluster_a'], 'guest_type' => 'qemu', 'vmid' => 100,
                'name' => 'guest-a', 'is_template' => 0, 'inventory_state' => 'active',
                'first_seen_run_id' => $context['run'], 'last_seen_run_id' => $context['run'],
                'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
            ]);
            $this->connection()->insert('scheduler_evaluation_runs', [
                'id' => $context['evaluation_run'], 'cycle_token' => random_bytes(16),
                'collector_fencing_token' => 1, 'evaluator_version' => 1,
                'payload_hash' => random_bytes(32), 'decision_count' => 2, 'gate_count' => 0,
                'started_at' => self::NOW, 'completed_at' => self::NOW, 'persisted_at' => self::NOW,
            ]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $context;
    }

    /** @param callable(): mixed $operation */
    private function assertRejected(callable $operation, string $reason): void
    {
        try {
            $operation();
            self::fail(sprintf('The backup-target schema accepted %s.', $reason));
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
