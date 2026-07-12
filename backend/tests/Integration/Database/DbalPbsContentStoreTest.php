<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\PbsContent\PbsContentCommit;
use App\Application\Inventory\PbsContent\PbsContentConflict;
use App\Application\Inventory\PbsContent\PbsContentRunStart;
use App\Application\Inventory\PbsContent\PbsContentScopeResult;
use App\Application\Inventory\PbsContent\PbsContentScopeStatus;
use App\Application\Inventory\PbsContent\PbsContentScopeType;
use App\Application\Inventory\PbsContent\PbsContentSnapshot;
use App\Application\Inventory\PbsContent\PbsNamespaceObservation;
use App\Application\Proxmox\Pbs\PbsBackupType;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsSnapshotObservation;
use App\Infrastructure\Persistence\MariaDb\DbalPbsContentStore;
use DateTimeImmutable;
use DateTimeZone;

final class DbalPbsContentStoreTest extends DatabaseTestCase
{
    private InventoryIdentifier $connectionId;
    private EndpointId $endpointId;
    private InventoryIdentifier $serverId;
    private InventoryIdentifier $datastoreRowId;
    private PbsContentSequentialIds $ids;
    private DbalPbsContentStore $store;
    private int $fence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionId = self::id('content-connection');
        $this->endpointId = new EndpointId(self::bytes('content-endpoint'));
        $this->serverId = self::id('content-server');
        $this->datastoreRowId = self::id('content-datastore');
        $this->ids = new PbsContentSequentialIds();
        $this->store = new DbalPbsContentStore($this->connection(), $this->ids);
        $this->insertStaticInventory();
    }

    public function testAuthoritativeApplyPersistsRootNestedGroupsAndArchivesThenReactivates(): void
    {
        [$lease, $parent] = $this->parent('seed');
        $run = self::id('content-seed');
        $this->store->begin($lease, $this->start($run, $parent));
        $root = PbsNamespace::root();
        $nested = new PbsNamespace('tenant/pve');
        $seed = new PbsContentSnapshot(
            [
                new PbsNamespaceObservation(new PbsDatastoreId('store_a'), $root),
                new PbsNamespaceObservation(new PbsDatastoreId('store_a'), $nested),
            ],
            [
                $this->snapshot($root, '100', 1),
                $this->snapshot($nested, '101', 2),
            ],
            [
                $this->scope(PbsContentScopeType::Namespaces, null),
                $this->scope(PbsContentScopeType::Snapshots, $root),
                $this->scope(PbsContentScopeType::Snapshots, $nested),
            ],
        );
        $result = $this->store->apply($lease, $this->commit($run, $parent, $seed, 1));
        self::assertSame('succeeded', $result->status->value);
        self::assertSame(['', 'tenant/pve'], $this->connection()->fetchFirstColumn(
            'SELECT namespace_path FROM pbs_namespaces ORDER BY namespace_path',
        ));
        self::assertSame(2, $this->countRows('pbs_backup_groups', "inventory_state = 'active'"));
        self::assertSame(2, $this->countRows('pbs_snapshots', "inventory_state = 'active'"));

        [$archiveLease, $archiveParent] = $this->parent('archive');
        $archiveRun = self::id('content-archive');
        $this->store->begin($archiveLease, $this->start($archiveRun, $archiveParent));
        $archiveSnapshot = new PbsContentSnapshot(
            [
                new PbsNamespaceObservation(new PbsDatastoreId('store_a'), $root),
                new PbsNamespaceObservation(new PbsDatastoreId('store_a'), $nested),
            ],
            [$this->snapshot($root, '100', 1)],
            [
                $this->scope(PbsContentScopeType::Namespaces, null),
                $this->scope(PbsContentScopeType::Snapshots, $root),
                $this->scope(PbsContentScopeType::Snapshots, $nested),
            ],
        );
        $this->store->apply($archiveLease, $this->commit($archiveRun, $archiveParent, $archiveSnapshot, 2));
        self::assertSame('active', $this->connection()->fetchOne(
            "SELECT inventory_state FROM pbs_namespaces WHERE namespace_path = 'tenant/pve'",
        ));
        self::assertSame(1, $this->countRows('pbs_snapshots', "inventory_state = 'archived'"));

        [$partialLease, $partialParent] = $this->parent('partial');
        $partialRun = self::id('content-partial');
        $this->store->begin($partialLease, $this->start($partialRun, $partialParent));
        $partial = new PbsContentSnapshot(
            [new PbsNamespaceObservation(new PbsDatastoreId('store_a'), $root)],
            [],
            [
                $this->scope(PbsContentScopeType::Namespaces, null, PbsContentScopeStatus::Partial),
                $this->scope(PbsContentScopeType::Snapshots, $root, PbsContentScopeStatus::Partial),
            ],
        );
        $this->store->apply($partialLease, $this->commit($partialRun, $partialParent, $partial, 3));
        self::assertSame(1, $this->countRows('pbs_snapshots', "inventory_state = 'active'"));

        [$reactivateLease, $reactivateParent] = $this->parent('reactivate');
        $reactivateRun = self::id('content-reactivate');
        $this->store->begin($reactivateLease, $this->start($reactivateRun, $reactivateParent));
        $this->store->apply($reactivateLease, $this->commit($reactivateRun, $reactivateParent, $seed, 4));
        self::assertSame(2, $this->countRows('pbs_namespaces', "inventory_state = 'active'"));
        self::assertSame(2, $this->countRows('pbs_snapshots', "inventory_state = 'active'"));
        self::assertSame(0, $this->countRows('pbs_snapshots', "inventory_state = 'archived'"));
    }

    public function testEmptyContentCommitIsASuccessfulNoopChildRun(): void
    {
        [$lease, $parent] = $this->parent('empty');
        $run = self::id('content-empty');
        $this->store->begin($lease, $this->start($run, $parent));

        $result = $this->store->apply(
            $lease,
            $this->commit($run, $parent, new PbsContentSnapshot([], [], []), 1),
        );

        self::assertSame('succeeded', $result->status->value);
        self::assertSame(0, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->archived);
        self::assertSame(0, $this->countRows('pbs_content_scope_results'));
        self::assertSame('succeeded', $this->connection()->fetchOne(
            'SELECT status FROM pbs_content_runs WHERE id = :id',
            ['id' => $run->binary()],
        ));
    }

    public function testDuplicateBeginRevisionDriftAndSecondApplyFailClosed(): void
    {
        [$lease, $parent] = $this->parent('fence');
        $run = self::id('content-fence');
        $start = $this->start($run, $parent);
        $this->store->begin($lease, $start);
        try {
            $this->store->begin($lease, new PbsContentRunStart(
                self::id('duplicate-run'), $parent, $this->connectionId, $this->endpointId,
                $this->binding(), 1, self::at(0),
            ));
            self::fail('Duplicate content parent was accepted.');
        } catch (PbsContentConflict) {
            self::assertSame(1, $this->countRows('pbs_content_runs'));
        }

        $snapshot = new PbsContentSnapshot(
            [new PbsNamespaceObservation(new PbsDatastoreId('store_a'), PbsNamespace::root())],
            [],
            [$this->scope(PbsContentScopeType::Namespaces, null)],
        );
        $commit = $this->commit($run, $parent, $snapshot, 1);
        $this->connection()->update('proxmox_connections', ['revision' => 2], ['id' => $this->connectionId->binary()]);
        try {
            $this->store->apply($lease, $commit);
            self::fail('Connection drift was accepted.');
        } catch (PbsContentConflict) {
            self::assertSame(0, $this->countRows('pbs_namespaces'));
            self::assertSame('running', $this->connection()->fetchOne('SELECT status FROM pbs_content_runs'));
        }
        $this->connection()->update('proxmox_connections', ['revision' => 1], ['id' => $this->connectionId->binary()]);
        $this->store->apply($lease, $commit);
        $this->expectException(CollectorLeaseOwnershipLost::class);
        $this->store->apply($lease, $commit);
    }

    /** @return array{CollectorLease, InventoryIdentifier} */
    private function parent(string $label): array
    {
        ++$this->fence;
        $now = $this->databaseNow();
        $expires = $now->modify('+1 hour');
        $worker = new CollectorWorkerId(self::bytes('content-worker'));
        $token = new CollectorCycleToken(self::bytes('content-cycle-'.$label));
        $this->connection()->executeStatement(
            "UPDATE collector_cycles SET status = 'succeeded', finished_at = :now, duration_ms = 0 WHERE status = 'running'",
            ['now' => $this->format($now)],
        );
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO worker_heartbeats (
                    worker_instance_id, worker_kind, status, started_at, heartbeat_at, expires_at, build_version
                ) VALUES (:worker, 'collector', 'ready', :now, :now, :expires, 'integration')
                ON DUPLICATE KEY UPDATE heartbeat_at = VALUES(heartbeat_at), expires_at = VALUES(expires_at)
                SQL,
            ['worker' => $worker->bytes, 'now' => $this->format($now), 'expires' => $this->format($expires)],
        );
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO collector_schedule (
                    schedule_name, grid_started_at, interval_seconds, next_scan_at, lease_owner, lease_token,
                    lease_fencing_token, lease_acquired_at, lease_expires_at, last_cycle_started_at, updated_at
                ) VALUES ('inventory', :now, 120, :now, :worker, :token, :fence, :now, :expires, :now, :now)
                ON DUPLICATE KEY UPDATE lease_owner = VALUES(lease_owner), lease_token = VALUES(lease_token),
                    lease_fencing_token = VALUES(lease_fencing_token), lease_acquired_at = VALUES(lease_acquired_at),
                    lease_expires_at = VALUES(lease_expires_at), updated_at = VALUES(updated_at)
                SQL,
            ['now' => $this->format($now), 'worker' => $worker->bytes, 'token' => $token->binary(), 'fence' => $this->fence, 'expires' => $this->format($expires)],
        );
        $this->connection()->insert('collector_cycles', [
            'cycle_token' => $token->binary(), 'schedule_name' => 'inventory',
            'worker_instance_id' => $worker->bytes, 'worker_kind' => 'collector',
            'fencing_token' => $this->fence, 'scheduled_for' => $this->format($now),
            'started_at' => $this->format($now), 'heartbeat_at' => $this->format($now), 'status' => 'running',
        ]);
        $parent = self::id('parent-'.$label);
        $this->connection()->insert('inventory_sync_runs', [
            'id' => $parent->binary(), 'cycle_token' => $token->binary(),
            'collector_fencing_token' => $this->fence, 'connection_id' => $this->connectionId->binary(),
            'expected_connection_revision' => 1, 'endpoint_id' => $this->endpointId->bytes,
            'status' => 'succeeded', 'authoritative' => 1,
            'started_at' => $this->format($now), 'heartbeat_at' => $this->format($now),
            'finished_at' => $this->format($now), 'applied_at' => $this->format($now),
        ]);
        $this->connection()->insert('inventory_sync_endpoint_attempts', [
            'id' => self::id('attempt-'.$label)->binary(), 'connection_id' => $this->connectionId->binary(),
            'sync_run_id' => $parent->binary(), 'endpoint_id' => $this->endpointId->bytes,
            'attempt_number' => 1, 'outcome' => 'selected', 'error_code' => null,
            'started_at' => $this->format($now), 'finished_at' => $this->format($now),
        ]);
        return [new CollectorLease($worker, $token, $this->fence, $expires), $parent];
    }

    private function insertStaticInventory(): void
    {
        $at = $this->format(self::at(0));
        $this->connection()->insert('proxmox_connections', [
            'id' => $this->connectionId->binary(), 'display_name' => 'PBS content integration',
            'product' => 'pbs', 'enabled' => 1, 'revision' => 1, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $this->endpointId->bytes, 'connection_id' => $this->connectionId->binary(),
            'host' => 'pbs-content.test', 'port' => 8007, 'priority' => 100, 'enabled' => 1,
            'tls_mode' => 'system_ca', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $seedRun = self::id('static-seed-run');
        $token = self::id('static-token');
        $staticWorker = self::bytes('static-worker');
        $this->connection()->insert('worker_heartbeats', [
            'worker_instance_id' => $staticWorker, 'worker_kind' => 'collector', 'status' => 'ready',
            'started_at' => $at, 'heartbeat_at' => $at,
            'expires_at' => $this->format(self::at(0)->modify('+1 hour')), 'build_version' => 'integration',
        ]);
        $this->connection()->insert('collector_schedule', [
            'schedule_name' => 'inventory', 'grid_started_at' => $at, 'interval_seconds' => 120,
            'next_scan_at' => $at, 'lease_fencing_token' => 1, 'updated_at' => $at,
        ]);
        $this->connection()->insert('collector_cycles', [
            'cycle_token' => $token->binary(), 'schedule_name' => 'inventory',
            'worker_instance_id' => $staticWorker, 'worker_kind' => 'collector',
            'fencing_token' => 1, 'scheduled_for' => $at, 'started_at' => $at,
            'heartbeat_at' => $at, 'finished_at' => $at, 'duration_ms' => 0, 'status' => 'succeeded',
        ]);
        $this->connection()->insert('inventory_sync_runs', [
            'id' => $seedRun->binary(), 'cycle_token' => $token->binary(), 'collector_fencing_token' => 1,
            'connection_id' => $this->connectionId->binary(), 'expected_connection_revision' => 1,
            'endpoint_id' => $this->endpointId->bytes, 'status' => 'succeeded', 'authoritative' => 1,
            'started_at' => $at, 'heartbeat_at' => $at, 'finished_at' => $at, 'applied_at' => $at,
        ]);
        $this->connection()->insert('proxmox_installation_bindings', [
            'connection_id' => $this->connectionId->binary(), 'product' => 'pbs',
            'identity_kind' => 'pbs_legacy_node', 'identity_value' => 'pbs-a',
            'legacy_endpoint_id' => $this->endpointId->bytes,
            'first_bound_run_id' => $seedRun->binary(), 'last_verified_run_id' => $seedRun->binary(),
            'first_bound_at' => $at, 'last_verified_at' => $at,
        ]);
        $this->connection()->insert('pbs_servers', [
            'id' => $this->serverId->binary(), 'connection_id' => $this->connectionId->binary(),
            'node_name' => 'pbs-a', 'version_major' => 4, 'version_minor' => 2, 'version_patch' => 0,
            'version_text' => '4.2.0', 'release_text' => '1', 'repo_id' => 'repo',
            'first_seen_run_id' => $seedRun->binary(), 'last_seen_run_id' => $seedRun->binary(),
            'first_seen_at' => $at, 'last_seen_at' => $at,
        ]);
        $this->connection()->insert('pbs_datastores', [
            'id' => $this->datastoreRowId->binary(), 'connection_id' => $this->connectionId->binary(),
            'server_id' => $this->serverId->binary(), 'datastore_name' => 'store_a',
            'backend_type' => 'filesystem', 'mount_status' => 'mounted', 'maintenance_mode' => null,
            'allows_backup_writes' => 1, 'inventory_state' => 'active',
            'first_seen_run_id' => $seedRun->binary(), 'last_seen_run_id' => $seedRun->binary(),
            'first_seen_at' => $at, 'last_seen_at' => $at, 'archived_at' => null,
        ]);
    }

    private function start(InventoryIdentifier $run, InventoryIdentifier $parent): PbsContentRunStart
    {
        return new PbsContentRunStart(
            $run, $parent, $this->connectionId, $this->endpointId, $this->binding(), 1, self::at(0),
        );
    }

    private function commit(
        InventoryIdentifier $run,
        InventoryIdentifier $parent,
        PbsContentSnapshot $snapshot,
        int $minute,
    ): PbsContentCommit {
        return new PbsContentCommit(
            $run, $parent, $this->connectionId, $this->endpointId, $this->binding(), 1,
            $snapshot, self::at($minute),
        );
    }

    private function binding(): InstallationBinding
    {
        return InstallationBinding::pbsLegacyNode('pbs-a', $this->endpointId);
    }

    private function scope(
        PbsContentScopeType $type,
        ?PbsNamespace $namespace,
        PbsContentScopeStatus $status = PbsContentScopeStatus::Complete,
    ): PbsContentScopeResult {
        return new PbsContentScopeResult(
            $type, new PbsDatastoreId('store_a'), $namespace, $status, 1,
            PbsContentScopeStatus::Complete === $status ? null : 'fixture_partial',
        );
    }

    private function snapshot(PbsNamespace $namespace, string $id, int $minute): PbsSnapshotObservation
    {
        return new PbsSnapshotObservation(
            new PbsDatastoreId('store_a'), $namespace, PbsBackupType::Vm, $id,
            self::at($minute), ['archive.blob'], false, null, null, 'backup@pbs', 100, null,
        );
    }

    /** @return int<0, max> */
    private function countRows(string $table, string $where = '1=1'): int
    {
        $value = $this->connection()->fetchOne("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return max(0, (int) $value);
        }
        throw new \RuntimeException('MariaDB returned an invalid row count.');
    }

    private function databaseNow(): DateTimeImmutable
    {
        $value = $this->connection()->fetchOne('SELECT UTC_TIMESTAMP(6)');
        self::assertIsString($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        return $date;
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function at(int $minute): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-07-12T00:%02d:00Z', $minute));
    }

    private static function id(string $label): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes($label));
    }

    private static function bytes(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }
}

final class PbsContentSequentialIds implements InventoryIdentifierGenerator
{
    private int $next = 1;
    public function generate(): InventoryIdentifier
    {
        return new InventoryIdentifier(pack('J', 0).pack('J', $this->next++));
    }
}
