<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Configuration\Policy\PolicyActivationAssessor;
use App\Application\Policy\ReadModel\PolicyListQuery;
use App\Application\Target\ReadModel\ConfiguredBackupTargetQuery;
use App\Infrastructure\Persistence\MariaDb\DbalConfiguredBackupTargetReadModel;
use App\Infrastructure\Persistence\MariaDb\DbalActivationEvidenceProvider;
use App\Infrastructure\Persistence\MariaDb\DbalPolicyReadModel;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;

final class DbalConfiguredBackupTargetReadModelTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-12 10:00:00.000000';

    public function testConfiguredTargetsUseRealKeysetFiltersAllowedNodesAndLosslessBytes(): void
    {
        $this->seed();
        $evidence = new DbalActivationEvidenceProvider($this->connection());
        $model = new DbalConfiguredBackupTargetReadModel($this->connection(), $evidence, $evidence,
            new FrozenClock(new DateTimeImmutable('2026-07-12T10:00:00Z')), new EvidenceFreshnessPolicy());

        $first = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(1), null, false));
        self::assertCount(1, $first->items);
        self::assertNotNull($first->nextCursor);
        self::assertSame('Alpha', $first->items[0]->displayName);
        self::assertSame('18446744073709551615', $first->items[0]->minimumFreeBytes?->value);
        self::assertSame(['node-a'], array_column($first->items[0]->allowedNodes, 'name'));
        self::assertSame([], $first->items[0]->toArray()['blockers']);
        self::assertTrue($first->items[0]->toArray()['canEnable']);

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

    public function testPolicyProjectionUsesFreshInventoryCapacityAndExecutorEvidenceInsteadOfTargetUpdateTime(): void
    {
        $this->seed();
        $target = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT target.id, target.connection_id, target.cluster_id, target.storage_id
            FROM backup_targets target WHERE target.display_name = 'Alpha'
            SQL);
        self::assertIsArray($target);
        $policy = random_bytes(16);
        $this->connection()->update('backup_targets', [
            'status' => 'enabled', 'created_at' => '2020-01-01 00:00:00.000000',
            'updated_at' => '2020-01-01 00:00:00.000000', 'disabled_at' => null,
        ], ['id' => $target['id']]);
        $capabilities = json_encode(['major' => 9], JSON_THROW_ON_ERROR);
        $this->connection()->insert('proxmox_capability_snapshots', [
            'id' => random_bytes(16), 'connection_id' => $target['connection_id'], 'product' => 'pve',
            'version_major' => 9, 'version_minor' => 0, 'raw_version' => '9.0', 'profile_version' => 1,
            'capabilities_json' => $capabilities, 'snapshot_hash' => hash('sha256', $capabilities, true),
            'first_observed_at' => self::NOW, 'last_observed_at' => self::NOW,
        ]);
        $this->connection()->insert('backup_policies', [
            'id' => $policy, 'connection_id' => $target['connection_id'], 'cluster_id' => $target['cluster_id'],
            'target_id' => $target['id'], 'display_name' => 'Fresh policy', 'status' => 'draft', 'revision' => 1,
            'policy_priority' => 100, 'backup_mode' => 'snapshot', 'compression' => 'zstd',
            'maximum_age_seconds' => 3600, 'schedule' => 'collector_cycle', 'keep_last' => 2,
            'retention_execution_enabled' => 0, 'failure_notification_recipients_json' => '[]',
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);

        $model = $this->policyModel();
        $fresh = $model->policies(new PolicyListQuery(new PageRequest(10)))->items[0]->toArray();
        self::assertTrue($fresh['canEnable']);
        self::assertSame([], $fresh['blockers']);

        $this->connection()->update('pve_node_storage_state', [
            'observed_at' => '2026-07-12 09:54:59.999999',
        ], ['storage_id' => $target['storage_id']]);
        $this->connection()->update('backup_targets', ['updated_at' => self::NOW], ['id' => $target['id']]);
        $stale = $model->policies(new PolicyListQuery(new PageRequest(10)))->items[0]->toArray();
        self::assertFalse($stale['canEnable']);
        self::assertSame(['target_evidence_stale'], $stale['blockers']);

        $this->connection()->update('pve_node_storage_state', [
            'observed_at' => self::NOW,
        ], ['storage_id' => $target['storage_id']]);
        $this->connection()->update('pve_clusters', [
            'first_seen_at' => '2026-07-12 09:54:59.999999',
            'last_seen_at' => '2026-07-12 09:54:59.999999',
        ], ['id' => $target['cluster_id']]);
        $this->connection()->update('pve_storages', [
            'last_seen_at' => self::NOW,
        ], ['id' => $target['storage_id']]);
        $clusterStale = $model->policies(new PolicyListQuery(new PageRequest(10)))->items[0]->toArray();
        self::assertFalse($clusterStale['canEnable']);
        self::assertSame(['target_evidence_stale'], $clusterStale['blockers']);
    }

    public function testAllowedNodeAvailabilityAndFreshnessAreActivationEvidence(): void
    {
        $this->seed();
        $model = $this->targetModel();

        $this->connection()->update('proxmox_connections', ['product' => 'pbs'], ['display_name' => 'PVE']);
        $wrongProduct = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        $this->assertHasBlocker($wrongProduct, 'candidate_rejected');
        $this->connection()->update('proxmox_connections', ['product' => 'pve'], ['display_name' => 'PVE']);

        $this->connection()->update('pve_storages', ['content_json' => '["images"]'], ['storage_name' => 'backup']);
        $missingContent = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        $this->assertHasBlocker($missingContent, 'candidate_rejected');
        $this->connection()->update('pve_storages', ['content_json' => '["backup"]'], ['storage_name' => 'backup']);

        $this->connection()->update('pve_storages', ['node_allowlist_json' => '["node-b"]'], ['storage_name' => 'backup']);
        $excludedNode = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        $this->assertHasBlocker($excludedNode, 'candidate_rejected');
        $this->connection()->update('pve_storages', ['node_allowlist_json' => null], ['storage_name' => 'backup']);

        $this->connection()->update('pve_nodes', ['api_status' => 'offline'], ['node_name' => 'node-a']);
        $offline = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        self::assertFalse($offline['canEnable']);
        $offlineBlockers = $offline['blockers'];
        self::assertIsArray($offlineBlockers);
        self::assertContains('candidate_rejected', $offlineBlockers);

        $this->connection()->update('pve_nodes', [
            'api_status' => 'online', 'first_seen_at' => '2026-07-12 09:55:00.000000',
            'last_seen_at' => '2026-07-12 09:55:00.000000',
        ], ['node_name' => 'node-a']);
        $boundary = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        self::assertTrue($boundary['canEnable']);

        $secondNode = $this->seedSecondAllowedNode('2026-07-12 10:00:00.000001');
        $mixedFuture = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        $mixedFutureBlockers = $mixedFuture['blockers'];
        self::assertIsArray($mixedFutureBlockers);
        foreach ([
            'candidate_evidence_future', 'inventory_evidence_future',
            'capacity_evidence_future', 'executor_evidence_future',
        ] as $blocker) {
            self::assertContains($blocker, $mixedFutureBlockers);
        }

        $this->connection()->update('pve_nodes', [
            'first_seen_at' => '2026-07-12 09:54:59.999999',
            'last_seen_at' => '2026-07-12 09:54:59.999999',
        ], ['id' => $secondNode]);
        $this->connection()->update('pve_node_storage_state', [
            'observed_at' => '2026-07-12 09:54:59.999999',
        ], ['node_id' => $secondNode]);
        $this->connection()->update('executor_permission_evidence', [
            'observed_at' => '2026-07-12 09:54:59.999999',
        ], ['node_id' => $secondNode]);
        $mixedStale = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        $mixedStaleBlockers = $mixedStale['blockers'];
        self::assertIsArray($mixedStaleBlockers);
        foreach ([
            'candidate_evidence_stale', 'inventory_evidence_stale',
            'capacity_evidence_stale', 'executor_evidence_stale',
        ] as $blocker) {
            self::assertContains($blocker, $mixedStaleBlockers);
        }

        $this->connection()->update('pve_nodes', [
            'first_seen_at' => '2026-07-12 09:54:59.999999',
            'last_seen_at' => '2026-07-12 09:54:59.999999',
        ], ['node_name' => 'node-a']);
        $stale = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        self::assertFalse($stale['canEnable']);
        $staleBlockers = $stale['blockers'];
        self::assertIsArray($staleBlockers);
        self::assertContains('candidate_evidence_stale', $staleBlockers);
        self::assertContains('inventory_evidence_stale', $staleBlockers);
    }

    public function testPbsMappingDatastoreAndCapacityAreRequiredFreshActivationEvidence(): void
    {
        $this->seed();
        $pbs = $this->seedPbsEvidenceForAlpha();
        $model = $this->targetModel();

        $ready = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        self::assertTrue($ready['canEnable']);
        self::assertSame([], $ready['blockers']);

        $this->connection()->update('pve_storage_pbs_mappings', [
            'namespace' => 'tenant',
        ], ['storage_id' => $pbs['storage']]);
        $this->connection()->update('pbs_namespaces', [
            'namespace_path' => 'tenant', 'namespace_depth' => 1,
        ], ['id' => $pbs['namespace']]);
        $nested = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        self::assertTrue($nested['canEnable']);

        $this->connection()->update('pve_storage_pbs_mappings', [
            'observed_at' => '2026-07-12 10:00:00.000001',
        ], ['storage_id' => $pbs['storage']]);
        $mappingFuture = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        $mappingFutureBlockers = $mappingFuture['blockers'];
        self::assertIsArray($mappingFutureBlockers);
        self::assertContains('candidate_evidence_future', $mappingFutureBlockers);
        self::assertContains('inventory_evidence_future', $mappingFutureBlockers);

        $this->connection()->update('pve_storage_pbs_mappings', [
            'observed_at' => self::NOW,
        ], ['storage_id' => $pbs['storage']]);
        $this->connection()->update('pbs_datastore_capacity_state', [
            'observed_at' => '2026-07-12 10:00:00.000001',
        ], ['datastore_id' => $pbs['datastore']]);
        $capacityFuture = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        $capacityFutureBlockers = $capacityFuture['blockers'];
        self::assertIsArray($capacityFutureBlockers);
        self::assertContains('capacity_evidence_future', $capacityFutureBlockers);

        $this->connection()->update('pbs_datastore_capacity_state', [
            'observed_at' => '2026-07-12 09:55:00.000000',
        ], ['datastore_id' => $pbs['datastore']]);
        self::assertTrue($model->targets(
            new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false),
        )->items[0]->toArray()['canEnable']);

        $this->connection()->update('pbs_datastore_capacity_state', [
            'observed_at' => '2026-07-12 09:54:59.999999',
        ], ['datastore_id' => $pbs['datastore']]);
        $pbsStale = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        $this->assertCannotEnable($pbsStale);
        $pbsStaleBlockers = $pbsStale['blockers'];
        self::assertIsArray($pbsStaleBlockers);
        self::assertContains('capacity_evidence_stale', $pbsStaleBlockers);

        $this->connection()->delete('pve_storage_pbs_mappings', ['storage_id' => $pbs['storage']]);
        $pbsMissing = $model->targets(new ConfiguredBackupTargetQuery(new PageRequest(10), 'Alpha', false))
            ->items[0]->toArray();
        $this->assertCannotEnable($pbsMissing);
        $pbsMissingBlockers = $pbsMissing['blockers'];
        self::assertIsArray($pbsMissingBlockers);
        self::assertContains('inventory_evidence_missing', $pbsMissingBlockers);
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
            foreach ([[$first, 'Alpha', '18446744073709551615', 2], [$second, 'Zulu 50%_copy', null, null]] as [$id, $name, $minimum, $parallel]) {
                $this->connection()->insert('backup_targets', [
                    'id' => $id, 'connection_id' => $connection, 'cluster_id' => $cluster,
                    'storage_id' => $storage, 'display_name' => $name, 'status' => 'disabled',
                    'revision' => 1, 'minimum_free_bytes' => $minimum, 'fixed_parallel_limit' => $parallel,
                    'created_at' => self::NOW, 'updated_at' => self::NOW, 'disabled_at' => self::NOW,
                ]);
            }
            $this->connection()->insert('backup_target_allowed_nodes', [
                'target_id' => $first, 'connection_id' => $connection, 'cluster_id' => $cluster,
                'node_id' => $node, 'created_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_node_storage_state', [
                'connection_id' => $connection, 'cluster_id' => $cluster, 'node_id' => $node,
                'storage_id' => $storage, 'enabled' => 1, 'active' => 1, 'shared' => 1,
                'capacity_status' => 'measured', 'total_bytes' => '18446744073709551615',
                'used_bytes' => 0, 'available_bytes' => '18446744073709551615',
                'observed_at' => self::NOW, 'sync_run_id' => $run,
            ]);
            $this->connection()->insert('executor_permission_evidence', [
                'id' => random_bytes(16), 'connection_id' => $connection, 'cluster_id' => $cluster,
                'target_id' => $first, 'node_id' => $node, 'storage_id' => $storage, 'guest_id' => null,
                'vm_backup_authorized' => 1, 'datastore_allocate_authorized' => 1,
                'authorized' => 1, 'observed_at' => self::NOW, 'revision' => 1,
            ]);
            $this->seedExecutorEvidenceFixtureConfiguration($connection);
            $this->publishExecutorEvidenceFixture($connection);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function policyModel(): DbalPolicyReadModel
    {
        $evidence = new DbalActivationEvidenceProvider($this->connection());
        return new DbalPolicyReadModel(
            $this->connection(), $evidence, new PolicyActivationAssessor(),
            new FrozenClock(new DateTimeImmutable('2026-07-12T10:00:00Z')),
            new EvidenceFreshnessPolicy(),
        );
    }

    private function targetModel(): DbalConfiguredBackupTargetReadModel
    {
        $evidence = new DbalActivationEvidenceProvider($this->connection());
        return new DbalConfiguredBackupTargetReadModel(
            $this->connection(), $evidence, $evidence,
            new FrozenClock(new DateTimeImmutable('2026-07-12T10:00:00Z')),
            new EvidenceFreshnessPolicy(),
        );
    }

    private function seedSecondAllowedNode(string $observedAt): string
    {
        $target = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT target.id, target.connection_id, target.cluster_id, target.storage_id
            FROM backup_targets target WHERE target.display_name = 'Alpha'
            SQL);
        self::assertIsArray($target);
        foreach (['id', 'connection_id', 'cluster_id', 'storage_id'] as $key) {
            self::assertIsString($target[$key]);
        }
        $node = random_bytes(16);
        $run = random_bytes(16);
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->connection()->insert('pve_nodes', [
                'id' => $node, 'connection_id' => $target['connection_id'],
                'cluster_id' => $target['cluster_id'], 'node_name' => 'node-b',
                'api_status' => 'online', 'inventory_state' => 'active',
                'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW, 'last_seen_at' => $observedAt,
            ]);
            $this->connection()->insert('backup_target_allowed_nodes', [
                'target_id' => $target['id'], 'connection_id' => $target['connection_id'],
                'cluster_id' => $target['cluster_id'], 'node_id' => $node, 'created_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_node_storage_state', [
                'connection_id' => $target['connection_id'], 'cluster_id' => $target['cluster_id'],
                'node_id' => $node, 'storage_id' => $target['storage_id'], 'enabled' => 1,
                'active' => 1, 'shared' => 1, 'capacity_status' => 'measured',
                'total_bytes' => '18446744073709551615', 'used_bytes' => 0,
                'available_bytes' => '18446744073709551615', 'observed_at' => $observedAt,
                'sync_run_id' => $run,
            ]);
            $this->connection()->insert('executor_permission_evidence', [
                'id' => random_bytes(16), 'connection_id' => $target['connection_id'],
                'cluster_id' => $target['cluster_id'], 'target_id' => $target['id'],
                'node_id' => $node, 'storage_id' => $target['storage_id'], 'guest_id' => null,
                'vm_backup_authorized' => 1, 'datastore_allocate_authorized' => 1,
                'authorized' => 1, 'observed_at' => $observedAt, 'revision' => 1,
            ]);
            $this->seedExecutorEvidenceFixtureConfiguration($target['connection_id']);
            $this->publishExecutorEvidenceFixture($target['connection_id']);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $node;
    }

    /** @param array<string, mixed> $projection */
    private function assertCannotEnable(array $projection): void
    {
        self::assertSame(false, $projection['canEnable'] ?? null);
    }

    /** @param array<string, mixed> $projection */
    private function assertHasBlocker(array $projection, string $blocker): void
    {
        $blockers = $projection['blockers'] ?? null;
        self::assertIsArray($blockers);
        self::assertContains($blocker, $blockers);
    }

    /** @return array{storage: string, datastore: string, namespace: string} */
    private function seedPbsEvidenceForAlpha(): array
    {
        $target = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT target.id, target.connection_id, target.cluster_id, target.storage_id
            FROM backup_targets target WHERE target.display_name = 'Alpha'
            SQL);
        self::assertIsArray($target);
        self::assertIsString($target['storage_id']);
        $pbsConnection = random_bytes(16);
        $server = random_bytes(16);
        $datastore = random_bytes(16);
        $namespace = random_bytes(16);
        $run = random_bytes(16);

        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->connection()->update('pve_storages', ['storage_type' => 'pbs'], ['id' => $target['storage_id']]);
            $this->connection()->insert('proxmox_connections', [
                'id' => $pbsConnection, 'display_name' => 'PBS', 'product' => 'pbs', 'enabled' => 1,
                'revision' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
            $this->connection()->insert('proxmox_connection_endpoints', [
                'id' => random_bytes(16), 'connection_id' => $pbsConnection, 'host' => 'pbs.example.test',
                'port' => 8007, 'priority' => 1, 'enabled' => 1, 'tls_mode' => 'system_ca',
                'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_storage_pbs_mappings', [
                'storage_id' => $target['storage_id'], 'connection_id' => $target['connection_id'],
                'cluster_id' => $target['cluster_id'], 'server' => 'pbs.example.test', 'port' => 8007,
                'datastore' => 'primary', 'namespace' => null, 'observed_at' => self::NOW,
                'sync_run_id' => $run,
            ]);
            $this->connection()->insert('pbs_servers', [
                'id' => $server, 'connection_id' => $pbsConnection, 'node_name' => 'pbs-a',
                'version_major' => 4, 'version_minor' => 0, 'version_patch' => 0,
                'version_text' => '4.0', 'release_text' => '1', 'repo_id' => 'repo',
                'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
            ]);
            $this->connection()->insert('pbs_datastores', [
                'id' => $datastore, 'connection_id' => $pbsConnection, 'server_id' => $server,
                'datastore_name' => 'primary', 'backend_type' => 'filesystem', 'mount_status' => 'mounted',
                'maintenance_mode' => null, 'allows_backup_writes' => 1, 'inventory_state' => 'active',
                'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
            ]);
            $this->connection()->insert('pbs_namespaces', [
                'id' => $namespace, 'connection_id' => $pbsConnection, 'server_id' => $server,
                'datastore_id' => $datastore, 'namespace_path' => '', 'namespace_depth' => 0,
                'parent_namespace_id' => null, 'inventory_state' => 'active',
                'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
            ]);
            $this->connection()->insert('pbs_datastore_capacity_state', [
                'datastore_id' => $datastore, 'connection_id' => $pbsConnection, 'server_id' => $server,
                'backend_type' => 'filesystem', 'semantics' => 'datastore_filesystem',
                'total_bytes' => '18446744073709551615', 'used_bytes' => 0,
                'available_bytes' => '18446744073709551615', 'observed_at' => self::NOW,
                'sync_run_id' => $run,
            ]);
            $this->connection()->update('backup_targets', [
                'pbs_connection_id' => $pbsConnection, 'pbs_datastore_id' => $datastore,
                'pbs_namespace_id' => $namespace,
            ], ['id' => $target['id']]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return ['storage' => $target['storage_id'], 'datastore' => $datastore, 'namespace' => $namespace];
    }
}
