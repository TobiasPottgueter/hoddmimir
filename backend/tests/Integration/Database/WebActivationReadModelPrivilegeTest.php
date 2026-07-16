<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Configuration\Policy\PolicyActivationAssessor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Policy\ReadModel\PolicyListQuery;
use App\Application\Target\ReadModel\ConfiguredBackupTargetQuery;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Target\BackupTargetId;
use App\Infrastructure\Persistence\MariaDb\DbalActivationEvidenceProvider;
use App\Infrastructure\Persistence\MariaDb\DbalConfiguredBackupTargetReadModel;
use App\Infrastructure\Persistence\MariaDb\DbalPolicyReadModel;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

final class WebActivationReadModelPrivilegeTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-16 12:00:00.000000';

    public function testWebRoleExecutesActivationEvidenceBackedTargetAndPolicyProjections(): void
    {
        $fixture = $this->seedProjectionFixture();
        $this->connection()->commit();

        $web = $this->webConnection();
        try {
            $evidence = new DbalActivationEvidenceProvider($web);
            $candidate = $evidence->candidateEvidence(new BackupTargetId($fixture['target']));
            self::assertTrue($candidate->candidate->accepted);
            self::assertTrue($candidate->inventory->accepted);
            self::assertTrue($candidate->capacity->accepted);

            $clock = new FrozenClock(new DateTimeImmutable('2026-07-16T12:00:00Z'));
            $freshness = new EvidenceFreshnessPolicy();
            $targets = (new DbalConfiguredBackupTargetReadModel(
                $web,
                $evidence,
                $evidence,
                $clock,
                $freshness,
            ))->targets(new ConfiguredBackupTargetQuery(
                new PageRequest(10),
                $fixture['targetName'],
                false,
            ));
            self::assertCount(1, $targets->items);
            self::assertSame($fixture['targetName'], $targets->items[0]->displayName);

            $policies = (new DbalPolicyReadModel(
                $web,
                $evidence,
                new PolicyActivationAssessor(),
                $clock,
                $freshness,
            ))->policies(new PolicyListQuery(
                new PageRequest(10),
                $fixture['policyName'],
                'draft',
            ));
            self::assertCount(1, $policies->items);
            self::assertSame($fixture['policyName'], $policies->items[0]->displayName);
        } finally {
            $web->close();
            $this->cleanupProjectionFixture($fixture['connection']);
        }
    }

    /**
     * @return array{connection: string, target: string, targetName: string, policyName: string}
     */
    private function seedProjectionFixture(): array
    {
        $connection = random_bytes(16);
        $cluster = random_bytes(16);
        $node = random_bytes(16);
        $storage = random_bytes(16);
        $target = random_bytes(16);
        $policy = random_bytes(16);
        $run = random_bytes(16);
        $suffix = bin2hex(substr($connection, 0, 6));
        $targetName = 'Web projection target '.$suffix;
        $policyName = 'Web projection policy '.$suffix;

        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->connection()->insert('proxmox_connections', [
                'id' => $connection,
                'display_name' => 'Web projection PVE '.$suffix,
                'product' => 'pve',
                'enabled' => 1,
                'revision' => 1,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ], ['id' => ParameterType::BINARY]);
            $capabilities = json_encode(['profile' => 'web-privilege-test'], JSON_THROW_ON_ERROR);
            $this->connection()->insert('proxmox_capability_snapshots', [
                'id' => random_bytes(16),
                'connection_id' => $connection,
                'product' => 'pve',
                'version_major' => 9,
                'version_minor' => 0,
                'raw_version' => '9.0.0',
                'profile_version' => 1,
                'capabilities_json' => $capabilities,
                'snapshot_hash' => hash('sha256', $capabilities, true),
                'first_observed_at' => self::NOW,
                'last_observed_at' => self::NOW,
            ], ['connection_id' => ParameterType::BINARY]);
            $this->connection()->insert('pve_clusters', [
                'id' => $cluster,
                'connection_id' => $connection,
                'external_name' => 'web-projection-cluster-'.$suffix,
                'topology' => 'clustered',
                'inventory_state' => 'active',
                'first_seen_run_id' => $run,
                'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_nodes', [
                'id' => $node,
                'connection_id' => $connection,
                'cluster_id' => $cluster,
                'node_name' => 'web-projection-node-'.$suffix,
                'api_status' => 'online',
                'inventory_state' => 'active',
                'first_seen_run_id' => $run,
                'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_storages', [
                'id' => $storage,
                'connection_id' => $connection,
                'cluster_id' => $cluster,
                'storage_name' => 'web-projection-storage-'.$suffix,
                'storage_type' => 'dir',
                'supports_backup' => 1,
                'shared' => 1,
                'disabled' => 0,
                'content_json' => '["backup"]',
                'inventory_state' => 'active',
                'first_seen_run_id' => $run,
                'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_node_storage_state', [
                'connection_id' => $connection,
                'cluster_id' => $cluster,
                'node_id' => $node,
                'storage_id' => $storage,
                'enabled' => 1,
                'active' => 1,
                'shared' => 1,
                'capacity_status' => 'measured',
                'total_bytes' => 1099511627776,
                'used_bytes' => 107374182400,
                'available_bytes' => 992137445376,
                'observed_at' => self::NOW,
                'sync_run_id' => $run,
            ]);
            $this->connection()->insert('backup_targets', [
                'id' => $target,
                'connection_id' => $connection,
                'cluster_id' => $cluster,
                'storage_id' => $storage,
                'display_name' => $targetName,
                'status' => 'disabled',
                'revision' => 1,
                'minimum_free_bytes' => 10737418240,
                'fixed_parallel_limit' => 1,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
                'disabled_at' => self::NOW,
            ]);
            $this->connection()->insert('backup_target_allowed_nodes', [
                'target_id' => $target,
                'connection_id' => $connection,
                'cluster_id' => $cluster,
                'node_id' => $node,
                'created_at' => self::NOW,
            ]);
            $this->connection()->insert('backup_policies', [
                'id' => $policy,
                'connection_id' => $connection,
                'cluster_id' => $cluster,
                'target_id' => $target,
                'display_name' => $policyName,
                'status' => 'draft',
                'revision' => 1,
                'policy_priority' => 300,
                'backup_mode' => 'snapshot',
                'compression' => 'zstd',
                'maximum_age_seconds' => 86400,
                'schedule' => 'collector_cycle',
                'keep_last' => 2,
                'retention_execution_enabled' => 0,
                'failure_notification_recipients_json' => '[]',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return [
            'connection' => $connection,
            'target' => $target,
            'targetName' => $targetName,
            'policyName' => $policyName,
        ];
    }

    private function cleanupProjectionFixture(string $connection): void
    {
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([
                'backup_policies',
                'backup_target_allowed_nodes',
                'backup_targets',
                'pve_node_storage_state',
                'pve_storages',
                'pve_nodes',
                'pve_clusters',
                'proxmox_capability_snapshots',
            ] as $table) {
                $this->connection()->delete(
                    $table,
                    ['connection_id' => $connection],
                    ['connection_id' => ParameterType::BINARY],
                );
            }
            $this->connection()->delete(
                'proxmox_connections',
                ['id' => $connection],
                ['id' => ParameterType::BINARY],
            );
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
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
}
