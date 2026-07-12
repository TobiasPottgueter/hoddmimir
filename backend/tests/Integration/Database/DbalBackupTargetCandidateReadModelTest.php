<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Target\ReadModel\BackupTargetCandidate;
use App\Application\Target\ReadModel\BackupTargetCandidateQuery;
use App\Infrastructure\Persistence\MariaDb\DbalBackupTargetCandidateReadModel;

final class DbalBackupTargetCandidateReadModelTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-12 10:00:00.000000';

    public function testItProjectsBoundedNodeAndValidatedPbsEvidenceWithoutActivationDefaults(): void
    {
        $this->seed();
        $model = new DbalBackupTargetCandidateReadModel($this->connection());

        $first = $model->candidates(new BackupTargetCandidateQuery(new PageRequest(1)));
        self::assertCount(1, $first->items);
        self::assertNotNull($first->nextCursor);
        $local = $first->items[0]->toArray();
        self::assertSame('local-a', $local['storageName']);
        self::assertFalse($local['canEnable']);
        self::assertSame(['freshness_policy_unconfigured'], $local['blockers']);
        $localNodes = $local['nodes'];
        self::assertIsArray($localNodes);
        self::assertIsArray($localNodes[0]);
        self::assertIsArray($localNodes[1]);
        self::assertSame('1000', $localNodes[0]['totalBytes']);
        self::assertSame([], $localNodes[0]['blockers']);
        self::assertSame(['node_state_missing'], $localNodes[1]['blockers']);

        $second = $model->candidates(new BackupTargetCandidateQuery(
            new PageRequest(1, $first->nextCursor),
        ));
        self::assertCount(1, $second->items);
        self::assertNull($second->nextCursor);
        $pbs = $second->items[0]->toArray();
        self::assertSame('pbs-b', $pbs['storageName']);
        $pbsEvidence = $pbs['pbs'];
        self::assertIsArray($pbsEvidence);
        self::assertSame('matched', $pbsEvidence['endpointMatch']);
        self::assertSame([], $pbsEvidence['blockers']);
        self::assertSame('datastore_filesystem', $pbsEvidence['capacitySemantics']);
        self::assertSame('9000', $pbsEvidence['availableBytes']);
        self::assertFalse($pbs['canEnable'], 'No freshness contract means fail-closed even with complete raw evidence.');
    }

    public function testHostAndPortEvidenceFailsClosedWhenUnresolvedOrAmbiguous(): void
    {
        $this->seed();
        $this->connection()->update(
            'proxmox_connection_endpoints',
            ['enabled' => 0],
            ['host' => 'pbs.example.test', 'port' => 8007],
        );
        $unresolved = $this->pbsCandidate()->pbs;
        self::assertNotNull($unresolved);
        self::assertSame('unresolved', $unresolved->endpointMatch->value);
        self::assertSame(['pbs_endpoint_unresolved'], array_column($unresolved->blockers, 'value'));

        $this->connection()->update(
            'proxmox_connection_endpoints',
            ['enabled' => 1],
            ['host' => 'pbs.example.test', 'port' => 8007],
        );
        $connection = random_bytes(16);
        $this->connection()->insert('proxmox_connections', [
            'id' => $connection, 'display_name' => 'Ambiguous PBS', 'product' => 'pbs', 'enabled' => 1,
            'revision' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => random_bytes(16), 'connection_id' => $connection, 'host' => 'pbs.example.test',
            'port' => 8007, 'priority' => 1, 'enabled' => 1, 'tls_mode' => 'system_ca',
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $ambiguous = $this->pbsCandidate()->pbs;
        self::assertNotNull($ambiguous);
        self::assertSame('ambiguous', $ambiguous->endpointMatch->value);
        self::assertSame(['pbs_endpoint_ambiguous'], array_column($ambiguous->blockers, 'value'));
    }

    public function testNonPbsEndpointAtMappedHostAndPortIsNotAConnectionMatch(): void
    {
        $this->seed();
        $pveConnection = $this->connection()->fetchOne(
            "SELECT id FROM proxmox_connections WHERE product = 'pve'",
        );
        self::assertIsString($pveConnection);
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => random_bytes(16), 'connection_id' => $pveConnection, 'host' => 'pbs.example.test',
            'port' => 8007, 'priority' => 2, 'enabled' => 1, 'tls_mode' => 'system_ca',
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $this->connection()->executeStatement(
            "UPDATE proxmox_connection_endpoints AS endpoint
             INNER JOIN proxmox_connections AS connection ON connection.id = endpoint.connection_id
             SET endpoint.enabled = 0
             WHERE connection.product = 'pbs' AND endpoint.host = 'pbs.example.test' AND endpoint.port = 8007",
        );

        $onlyNonPbs = $this->pbsCandidate()->pbs;
        self::assertNotNull($onlyNonPbs);
        self::assertSame('unresolved', $onlyNonPbs->endpointMatch->value);
        self::assertSame(['pbs_endpoint_unresolved'], array_column($onlyNonPbs->blockers, 'value'));

        $this->connection()->executeStatement(
            "UPDATE proxmox_connection_endpoints AS endpoint
             INNER JOIN proxmox_connections AS connection ON connection.id = endpoint.connection_id
             SET endpoint.enabled = 1
             WHERE connection.product = 'pbs' AND endpoint.host = 'pbs.example.test' AND endpoint.port = 8007",
        );

        $withOnePbs = $this->pbsCandidate()->pbs;
        self::assertNotNull($withOnePbs);
        self::assertSame('matched', $withOnePbs->endpointMatch->value);
        self::assertSame([], array_column($withOnePbs->blockers, 'value'));
    }

    public function testProductContentAndNodeOnlineGatesAreServerSideAndTyped(): void
    {
        $this->seed();
        $this->connection()->executeStatement("UPDATE proxmox_connections SET product = 'pbs' WHERE display_name = 'PVE'");
        $this->connection()->executeStatement("UPDATE pve_storages SET content_json = '[\"iso\"]' WHERE storage_name = 'local-a'");
        $this->connection()->executeStatement("UPDATE pve_nodes SET api_status = 'offline' WHERE node_name = 'node-a'");

        $page = (new DbalBackupTargetCandidateReadModel($this->connection()))->candidates(
            new BackupTargetCandidateQuery(new PageRequest(10)),
        );
        $local = array_values(array_filter(
            $page->items,
            static fn (BackupTargetCandidate $candidate): bool => 'local-a' === $candidate->storageName,
        ))[0];
        self::assertContains('connection_not_pve', array_column($local->blockers, 'value'));
        self::assertContains('backup_content_unsupported', array_column($local->blockers, 'value'));
        self::assertContains('no_usable_node', array_column($local->blockers, 'value'));
        self::assertContains('node_offline', array_column($local->nodes[0]->blockers, 'value'));
    }

    private function pbsCandidate(): BackupTargetCandidate
    {
        $items = (new DbalBackupTargetCandidateReadModel($this->connection()))->candidates(
            new BackupTargetCandidateQuery(new PageRequest(10)),
        )->items;
        $matches = array_values(array_filter(
            $items,
            static fn (BackupTargetCandidate $item): bool => 'pbs-b' === $item->storageName,
        ));
        self::assertCount(1, $matches);
        return $matches[0];
    }

    private function seed(): void
    {
        $pveConnection = random_bytes(16);
        $pbsConnection = random_bytes(16);
        $endpoint = random_bytes(16);
        $run = random_bytes(16);
        $cluster = random_bytes(16);
        $nodeA = random_bytes(16);
        $nodeB = random_bytes(16);
        $local = random_bytes(16);
        $pbsStorage = random_bytes(16);
        $server = random_bytes(16);
        $datastore = random_bytes(16);
        $namespace = random_bytes(16);

        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([[$pveConnection, 'PVE', 'pve'], [$pbsConnection, 'PBS', 'pbs']] as [$id, $name, $product]) {
                $this->connection()->insert('proxmox_connections', [
                    'id' => $id, 'display_name' => $name, 'product' => $product, 'enabled' => 1,
                    'revision' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW,
                ]);
            }
            $this->connection()->insert('proxmox_connection_endpoints', [
                'id' => $endpoint, 'connection_id' => $pbsConnection, 'host' => 'pbs.example.test',
                'port' => 8007, 'priority' => 1, 'enabled' => 1, 'tls_mode' => 'system_ca',
                'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
            $this->connection()->insert('inventory_sync_runs', [
                'id' => $run, 'cycle_token' => random_bytes(16), 'collector_fencing_token' => 1,
                'connection_id' => $pveConnection, 'expected_connection_revision' => 1,
                'status' => 'succeeded', 'authoritative' => 1, 'started_at' => self::NOW,
                'heartbeat_at' => self::NOW, 'finished_at' => self::NOW, 'applied_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_clusters', [
                'id' => $cluster, 'connection_id' => $pveConnection, 'external_name' => 'cluster-a',
                'topology' => 'clustered', 'inventory_state' => 'active', 'first_seen_run_id' => $run,
                'last_seen_run_id' => $run, 'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
            ]);
            foreach ([[$nodeA, 'node-a'], [$nodeB, 'node-b']] as [$id, $name]) {
                $this->connection()->insert('pve_nodes', [
                    'id' => $id, 'connection_id' => $pveConnection, 'cluster_id' => $cluster,
                    'node_name' => $name, 'api_status' => 'online', 'inventory_state' => 'active',
                    'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                    'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
                ]);
            }
            foreach ([[$local, 'local-a', 'dir'], [$pbsStorage, 'pbs-b', 'pbs']] as [$id, $name, $type]) {
                $this->connection()->insert('pve_storages', [
                    'id' => $id, 'connection_id' => $pveConnection, 'cluster_id' => $cluster,
                    'storage_name' => $name, 'storage_type' => $type, 'supports_backup' => 1,
                    'disabled' => 0, 'content_json' => '["backup"]', 'shared' => 1,
                    'inventory_state' => 'active', 'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                    'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
                ]);
                $this->connection()->insert('pve_node_storage_state', [
                    'connection_id' => $pveConnection, 'cluster_id' => $cluster,
                    'node_id' => $nodeA, 'storage_id' => $id, 'enabled' => 1, 'active' => 1,
                    'shared' => 1, 'capacity_status' => 'measured', 'total_bytes' => 1000,
                    'used_bytes' => 100, 'available_bytes' => 900, 'observed_at' => self::NOW,
                    'sync_run_id' => $run,
                ]);
            }
            $this->connection()->insert('pve_storage_pbs_mappings', [
                'storage_id' => $pbsStorage, 'connection_id' => $pveConnection, 'cluster_id' => $cluster,
                'server' => 'pbs.example.test', 'port' => 8007, 'datastore' => 'primary',
                'namespace' => null, 'observed_at' => self::NOW, 'sync_run_id' => $run,
            ]);
            $this->connection()->insert('pbs_servers', [
                'id' => $server, 'connection_id' => $pbsConnection, 'node_name' => 'pbs-a',
                'version_major' => 4, 'version_minor' => 2, 'version_patch' => 0,
                'version_text' => '4.2', 'release_text' => '1', 'repo_id' => 'repo',
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
            $this->connection()->insert('pbs_datastore_capacity_state', [
                'datastore_id' => $datastore, 'connection_id' => $pbsConnection, 'server_id' => $server,
                'backend_type' => 'filesystem', 'semantics' => 'datastore_filesystem',
                'total_bytes' => 10000, 'used_bytes' => 1000, 'available_bytes' => 9000,
                'observed_at' => self::NOW, 'sync_run_id' => $run,
            ]);
            $this->connection()->insert('pbs_namespaces', [
                'id' => $namespace, 'connection_id' => $pbsConnection, 'server_id' => $server,
                'datastore_id' => $datastore, 'namespace_path' => '', 'namespace_depth' => 0,
                'parent_namespace_id' => null, 'inventory_state' => 'active',
                'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
            ]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
