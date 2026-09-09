<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\Connection\InstallationBindingKind;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\Pbs\PbsCapacityObservation;
use App\Application\Inventory\Pbs\PbsDatastoreObservation;
use App\Application\Inventory\Pbs\PbsInventoryApplyResult;
use App\Application\Inventory\Pbs\PbsInventoryApplyStatus;
use App\Application\Inventory\Pbs\PbsInventoryCommit;
use App\Application\Inventory\Pbs\PbsInventoryConflict;
use App\Application\Inventory\Pbs\PbsInventoryScopeResult;
use App\Application\Inventory\Pbs\PbsInventoryStore;
use App\Application\Inventory\Pve\PveEndpointAttempt;
use App\Application\Inventory\Pve\PveSyncRunFailure;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalPbsInventoryStore implements PbsInventoryStore
{
    private const string SCHEDULE_NAME = 'inventory';

    public function __construct(
        private Connection $connection,
        private InventoryIdentifierGenerator $identifierGenerator,
    ) {
    }

    public function beginRun(CollectorLease $lease, PveSyncRunStart $start): void
    {
        $this->connection->transactional(function (Connection $connection) use ($lease, $start): void {
            $this->lockAndAssertFence($connection, $lease);
            $this->lockAndAssertConnection($connection, $start->connectionId, $start->expectedConnectionRevision);
            $at = $this->format($start->startedAt);
            $connection->insert('inventory_sync_runs', [
                'id' => $start->runId->binary(),
                'cycle_token' => $lease->token->binary(),
                'collector_fencing_token' => $lease->fencingToken,
                'connection_id' => $start->connectionId->binary(),
                'expected_connection_revision' => $start->expectedConnectionRevision,
                'status' => 'running',
                'authoritative' => 0,
                'started_at' => $at,
                'heartbeat_at' => $at,
            ]);
            $this->assertFenceBeforeCommit($connection, $lease);
        });
    }

    public function recordEndpointAttempt(CollectorLease $lease, PveEndpointAttempt $attempt): void
    {
        $this->connection->transactional(function (Connection $connection) use ($lease, $attempt): void {
            $this->lockAndAssertFence($connection, $lease);
            $run = $this->lockAndAssertRun($connection, $lease, $attempt->runId, $attempt->connectionId);
            $this->lockAndAssertConnection(
                $connection,
                $attempt->connectionId,
                $this->integer($run, 'expected_connection_revision'),
            );
            if (null !== ($run['endpoint_id'] ?? null)) {
                throw new PbsInventoryConflict('The PBS inventory run already selected an endpoint.');
            }
            $owned = $connection->fetchOne(
                'SELECT COUNT(*) FROM proxmox_connection_endpoints WHERE connection_id = :connection AND id = :endpoint FOR UPDATE',
                ['connection' => $attempt->connectionId->binary(), 'endpoint' => $attempt->endpointId->binary()],
            );
            if (1 !== $this->count($owned)) {
                throw new PbsInventoryConflict('The PBS endpoint attempt is not owned by the connection.');
            }
            $connection->insert('inventory_sync_endpoint_attempts', [
                'id' => $attempt->attemptId->binary(),
                'connection_id' => $attempt->connectionId->binary(),
                'sync_run_id' => $attempt->runId->binary(),
                'endpoint_id' => $attempt->endpointId->binary(),
                'attempt_number' => $attempt->attemptNumber,
                'outcome' => $attempt->outcome->value,
                'error_code' => $attempt->errorCode,
                'started_at' => $this->format($attempt->startedAt),
                'finished_at' => $this->format($attempt->finishedAt),
            ]);
            $values = ['heartbeat_at' => $this->format($attempt->finishedAt)];
            if ('selected' === $attempt->outcome->value) {
                $values['endpoint_id'] = $attempt->endpointId->binary();
            }
            $connection->update('inventory_sync_runs', $values, ['id' => $attempt->runId->binary()]);
            $this->assertFenceBeforeCommit($connection, $lease);
        });
    }

    public function finishWithoutSnapshot(CollectorLease $lease, PveSyncRunFailure $failure): void
    {
        $this->connection->transactional(function (Connection $connection) use ($lease, $failure): void {
            $this->lockAndAssertFence($connection, $lease);
            $run = $this->lockAndAssertRun($connection, $lease, $failure->runId, $failure->connectionId);
            if (null !== ($run['applied_at'] ?? null)) {
                throw new PbsInventoryConflict('The PBS inventory run was already applied.');
            }
            $expected = $this->integer($run, 'expected_connection_revision');
            if ($expected !== $failure->expectedConnectionRevision) {
                throw new PbsInventoryConflict('The PBS failure revision differs from its run.');
            }
            $changed = !$this->lockConnectionAtRevision($connection, $failure->connectionId, $expected);
            $at = $this->format($failure->finishedAt);
            $updated = $connection->update('inventory_sync_runs', [
                'status' => 'failed',
                'authoritative' => 0,
                'heartbeat_at' => $at,
                'finished_at' => $at,
                'error_code' => $changed ? ConnectionReadFailureCode::ConnectionChanged->value : $failure->failureCode->value,
                'error_summary' => null,
            ], ['id' => $failure->runId->binary(), 'status' => 'running', 'applied_at' => null]);
            if (1 !== $updated) {
                throw new PbsInventoryConflict('The PBS inventory run could not be finished exactly once.');
            }
            if (!$changed) {
                $this->recordOnboardingInventoryState(
                    $connection,
                    $failure->connectionId->binary(),
                    $failure->runId->binary(),
                    'inventory_failed',
                    $at,
                );
            }
            $this->assertFenceBeforeCommit($connection, $lease);
        });
    }

    public function apply(CollectorLease $lease, PbsInventoryCommit $inventory): PbsInventoryApplyResult
    {
        return $this->connection->transactional(function (Connection $connection) use ($lease, $inventory): PbsInventoryApplyResult {
            $this->lockAndAssertFence($connection, $lease);
            $run = $this->lockAndAssertRun($connection, $lease, $inventory->runId, $inventory->connectionId);
            if (null !== ($run['applied_at'] ?? null)) {
                throw new PbsInventoryConflict('The PBS inventory run was already applied.');
            }
            $expected = $this->integer($run, 'expected_connection_revision');
            if ($expected !== $inventory->expectedConnectionRevision) {
                throw new PbsInventoryConflict('The PBS inventory revision differs from its run.');
            }
            $this->lockAndAssertConnection($connection, $inventory->connectionId, $expected);
            $this->assertSelectedEndpoint($connection, $inventory, $run);

            $binding = $connection->fetchAssociative(
                'SELECT * FROM proxmox_installation_bindings WHERE connection_id = :id FOR UPDATE',
                ['id' => $inventory->connectionId->binary()],
            );
            $diagnosticOnly = false;
            if (false === $binding) {
                if (!$inventory->isFullyAuthoritative()) {
                    $diagnosticOnly = true;
                } else {
                    $this->insertBinding($connection, $inventory);
                }
            } else {
                $this->assertOrUpgradeBinding($connection, $binding, $inventory);
            }

            $created = 0;
            $updated = 0;
            $archived = 0;
            if (!$diagnosticOnly) {
                [$serverId, $serverCreated] = $this->upsertServer($connection, $inventory);
                $serverCreated ? ++$created : ++$updated;
                $serverStatus = $inventory->server->status;
                if (null !== $serverStatus) {
                    $this->upsertServerStatus($connection, $inventory, $serverId, $serverStatus);
                }
                $storeIds = [];
                foreach ($inventory->datastores as $store) {
                    [$storeId, $storeCreated] = $this->upsertDatastore($connection, $inventory, $serverId, $store);
                    $storeIds[$store->id] = $storeId;
                    $storeCreated ? ++$created : ++$updated;
                }
                foreach ($inventory->capacities as $capacity) {
                    $this->upsertCapacity(
                        $connection,
                        $inventory,
                        $serverId,
                        $storeIds[$capacity->datastoreId],
                        $capacity,
                    );
                }
                if ($inventory->isFullyAuthoritative()) {
                    $archived += $this->archiveUnseenDatastores($connection, $inventory, $serverId);
                }
                $connection->update('proxmox_installation_bindings', [
                    'last_verified_run_id' => $inventory->runId->binary(),
                    'last_verified_at' => $this->format($inventory->observedAt),
                ], ['connection_id' => $inventory->connectionId->binary()]);
            }

            $this->insertScope($connection, $inventory, $inventory->systemScope);
            $this->insertScope($connection, $inventory, $inventory->datastoreScope);
            foreach ($inventory->statusScopes as $scope) {
                $this->insertScope($connection, $inventory, $scope);
            }
            $status = $inventory->overallStatus();
            $at = $this->format($inventory->observedAt);
            $runUpdated = $connection->update('inventory_sync_runs', [
                'endpoint_id' => $inventory->endpointId->binary(),
                'status' => $status,
                'authoritative' => 'succeeded' === $status ? 1 : 0,
                'heartbeat_at' => $at,
                'finished_at' => $at,
                'applied_at' => $at,
                'nodes_seen' => 1,
                'guests_seen' => 0,
                'storages_seen' => count($inventory->datastores),
                'objects_created' => $created,
                'objects_updated' => $updated,
                'objects_archived' => $archived,
            ], ['id' => $inventory->runId->binary(), 'status' => 'running', 'applied_at' => null]);
            if (1 !== $runUpdated) {
                throw new PbsInventoryConflict('The PBS inventory run could not be applied exactly once.');
            }
            $this->recordOnboardingInventoryState(
                $connection,
                $inventory->connectionId->binary(),
                $inventory->runId->binary(),
                match ($status) {
                    'succeeded' => 'inventory_verified',
                    'partial' => 'inventory_partial',
                    default => 'inventory_failed',
                },
                $at,
            );
            $this->assertFenceBeforeCommit($connection, $lease);

            return new PbsInventoryApplyResult(
                PbsInventoryApplyStatus::from($status),
                $created,
                $updated,
                $archived,
                $diagnosticOnly,
            );
        });
    }

    private function recordOnboardingInventoryState(
        Connection $connection,
        string $connectionId,
        string $runId,
        string $state,
        string $changedAt,
    ): void {
        $connection->update('proxmox_connection_onboarding_state', [
            'state' => $state,
            'inventory_status_changed_at' => $changedAt,
            'last_inventory_run_id' => $runId,
        ], ['connection_id' => $connectionId]);
    }

    /** @return array{InventoryIdentifier, bool} */
    private function upsertServer(Connection $connection, PbsInventoryCommit $inventory): array
    {
        $row = $connection->fetchAssociative(
            'SELECT * FROM pbs_servers WHERE connection_id = :connection FOR UPDATE',
            ['connection' => $inventory->connectionId->binary()],
        );
        $values = [
            'node_name' => $inventory->server->node,
            'version_major' => $inventory->server->version->major,
            'version_minor' => $inventory->server->version->minor,
            'version_patch' => $inventory->server->version->patch,
            'version_text' => $inventory->server->version->version,
            'release_text' => $inventory->server->version->release,
            'repo_id' => $inventory->server->version->repoId,
            'last_seen_run_id' => $inventory->runId->binary(),
            'last_seen_at' => $this->format($inventory->observedAt),
        ];
        if (false === $row) {
            $id = $this->identifierGenerator->generate();
            $connection->insert('pbs_servers', [
                'id' => $id->binary(),
                'connection_id' => $inventory->connectionId->binary(),
                'first_seen_run_id' => $inventory->runId->binary(),
                'first_seen_at' => $this->format($inventory->observedAt),
                ...$values,
            ]);
            return [$id, true];
        }
        $id = new InventoryIdentifier($this->binary($row, 'id'));
        $connection->update('pbs_servers', $values, ['id' => $id->binary()]);
        return [$id, false];
    }

    private function upsertServerStatus(
        Connection $connection,
        PbsInventoryCommit $inventory,
        InventoryIdentifier $serverId,
        PbsNodeStatus $status,
    ): void {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO pbs_server_status (
                    server_id, connection_id, uptime_seconds, memory_total_bytes, memory_used_bytes,
                    root_total_bytes, root_used_bytes, root_available_bytes, observed_at, sync_run_id
                ) VALUES (
                    :server, :connection, :uptime, :memory_total, :memory_used,
                    :root_total, :root_used, :root_available, :observed, :run
                ) ON DUPLICATE KEY UPDATE
                    uptime_seconds = VALUES(uptime_seconds), memory_total_bytes = VALUES(memory_total_bytes),
                    memory_used_bytes = VALUES(memory_used_bytes), root_total_bytes = VALUES(root_total_bytes),
                    root_used_bytes = VALUES(root_used_bytes), root_available_bytes = VALUES(root_available_bytes),
                    observed_at = VALUES(observed_at), sync_run_id = VALUES(sync_run_id)
                SQL,
            [
                'server' => $serverId->binary(), 'connection' => $inventory->connectionId->binary(),
                'uptime' => $status->uptimeSeconds, 'memory_total' => $status->memoryTotalBytes,
                'memory_used' => $status->memoryUsedBytes, 'root_total' => $status->rootTotalBytes,
                'root_used' => $status->rootUsedBytes, 'root_available' => $status->rootAvailableBytes,
                'observed' => $this->format($inventory->observedAt), 'run' => $inventory->runId->binary(),
            ],
        );
    }

    /** @return array{InventoryIdentifier, bool} */
    private function upsertDatastore(
        Connection $connection,
        PbsInventoryCommit $inventory,
        InventoryIdentifier $serverId,
        PbsDatastoreObservation $store,
    ): array {
        $row = $connection->fetchAssociative(
            'SELECT * FROM pbs_datastores WHERE server_id = :server AND datastore_name = :name FOR UPDATE',
            ['server' => $serverId->binary(), 'name' => $store->id],
        );
        $values = [
            'backend_type' => $store->backendType->value,
            'mount_status' => $store->mountStatus->value,
            'maintenance_mode' => $store->maintenanceMode?->value,
            'allows_backup_writes' => (int) $store->allowsBackupWrites,
            'inventory_state' => 'active',
            'last_seen_run_id' => $inventory->runId->binary(),
            'last_seen_at' => $this->format($inventory->observedAt),
            'archived_at' => null,
        ];
        if (false === $row) {
            $id = $this->identifierGenerator->generate();
            $connection->insert('pbs_datastores', [
                'id' => $id->binary(), 'connection_id' => $inventory->connectionId->binary(),
                'server_id' => $serverId->binary(), 'datastore_name' => $store->id,
                'first_seen_run_id' => $inventory->runId->binary(),
                'first_seen_at' => $this->format($inventory->observedAt), ...$values,
            ]);
            return [$id, true];
        }
        $id = new InventoryIdentifier($this->binary($row, 'id'));
        if (($row['backend_type'] ?? null) !== $store->backendType->value) {
            // A directly observed backend change invalidates the old
            // backend-bound capacity even in an otherwise partial run. This
            // is fail-closed state replacement, not an absence-based diff.
            $connection->delete('pbs_datastore_capacity_state', ['datastore_id' => $id->binary()]);
        }
        $connection->update('pbs_datastores', $values, ['id' => $id->binary()]);
        return [$id, false];
    }

    private function upsertCapacity(
        Connection $connection,
        PbsInventoryCommit $inventory,
        InventoryIdentifier $serverId,
        InventoryIdentifier $storeId,
        PbsCapacityObservation $capacity,
    ): void {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO pbs_datastore_capacity_state (
                    datastore_id, connection_id, server_id, backend_type, semantics,
                    total_bytes, used_bytes, available_bytes, observed_at, sync_run_id
                ) VALUES (
                    :datastore, :connection, :server, :backend, :semantics,
                    :total, :used, :available, :observed, :run
                ) ON DUPLICATE KEY UPDATE
                    backend_type = VALUES(backend_type), semantics = VALUES(semantics),
                    total_bytes = VALUES(total_bytes), used_bytes = VALUES(used_bytes),
                    available_bytes = VALUES(available_bytes), observed_at = VALUES(observed_at),
                    sync_run_id = VALUES(sync_run_id)
                SQL,
            [
                'datastore' => $storeId->binary(), 'connection' => $inventory->connectionId->binary(),
                'server' => $serverId->binary(), 'backend' => $capacity->backendType->value,
                'semantics' => $capacity->semantics->value, 'total' => $capacity->totalBytes,
                'used' => $capacity->usedBytes, 'available' => $capacity->availableBytes,
                'observed' => $this->format($inventory->observedAt), 'run' => $inventory->runId->binary(),
            ],
        );
    }

    private function archiveUnseenDatastores(
        Connection $connection,
        PbsInventoryCommit $inventory,
        InventoryIdentifier $serverId,
    ): int {
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id FROM pbs_datastores
                WHERE server_id = :server AND inventory_state = 'active' AND last_seen_run_id <> :run
                ORDER BY datastore_name FOR UPDATE
                SQL,
            ['server' => $serverId->binary(), 'run' => $inventory->runId->binary()],
        );
        foreach ($rows as $row) {
            $id = $this->binary($row, 'id');
            $connection->delete('pbs_datastore_capacity_state', ['datastore_id' => $id]);
            $connection->update('pbs_datastores', [
                'inventory_state' => 'archived',
                'archived_at' => $this->format($inventory->observedAt),
            ], ['id' => $id, 'inventory_state' => 'active']);
        }
        return count($rows);
    }

    /** @param array<string, mixed> $binding */
    private function assertOrUpgradeBinding(Connection $connection, array $binding, PbsInventoryCommit $inventory): void
    {
        if (($binding['product'] ?? null) !== 'pbs') {
            throw new PbsInventoryConflict('The persisted binding is not a PBS binding.');
        }
        $kind = $binding['identity_kind'] ?? null;
        $value = $binding['identity_value'] ?? null;
        $legacyEndpoint = $binding['legacy_endpoint_id'] ?? null;
        if ($kind === $inventory->binding->kind->value
            && is_string($value)
            && hash_equals($inventory->binding->identity, $value)
            && $legacyEndpoint === $inventory->binding->legacyEndpointId?->bytes) {
            return;
        }
        if ('pbs_legacy_node' === $kind
            && InstallationBindingKind::PbsInstance === $inventory->binding->kind
            && $inventory->isFullyAuthoritative()
            && is_string($legacyEndpoint)
            && hash_equals($inventory->endpointId->binary(), $legacyEndpoint)
            && is_string($value)
            && hash_equals($inventory->server->node, $value)) {
            $connection->update('proxmox_installation_bindings', [
                'identity_kind' => 'pbs_instance',
                'identity_value' => $inventory->binding->identity,
                'legacy_endpoint_id' => null,
            ], ['connection_id' => $inventory->connectionId->binary()]);
            return;
        }
        throw new PbsInventoryConflict('The PBS installation binding does not match this inventory commit.');
    }

    private function insertBinding(Connection $connection, PbsInventoryCommit $inventory): void
    {
        $at = $this->format($inventory->observedAt);
        $connection->insert('proxmox_installation_bindings', [
            'connection_id' => $inventory->connectionId->binary(),
            'product' => 'pbs',
            'identity_kind' => $inventory->binding->kind->value,
            'identity_value' => $inventory->binding->identity,
            'legacy_endpoint_id' => $inventory->binding->legacyEndpointId?->bytes,
            'first_bound_run_id' => $inventory->runId->binary(),
            'last_verified_run_id' => $inventory->runId->binary(),
            'first_bound_at' => $at,
            'last_verified_at' => $at,
        ]);
    }

    private function insertScope(Connection $connection, PbsInventoryCommit $inventory, PbsInventoryScopeResult $scope): void
    {
        $connection->insert('inventory_sync_scope_results', [
            'connection_id' => $inventory->connectionId->binary(),
            'sync_run_id' => $inventory->runId->binary(),
            'scope_type' => $scope->scope->value,
            'scope_key' => $scope->key,
            'status' => $scope->status->value,
            'observed_at' => $this->format($inventory->observedAt),
        ]);
    }

    /** @param array<string, mixed> $run */
    private function assertSelectedEndpoint(Connection $connection, PbsInventoryCommit $inventory, array $run): void
    {
        $endpoint = $run['endpoint_id'] ?? null;
        if (!is_string($endpoint) || !hash_equals($inventory->endpointId->binary(), $endpoint)) {
            throw new PbsInventoryConflict('The PBS inventory endpoint differs from its run.');
        }
        $selected = $connection->fetchOne(
            "SELECT COUNT(*) FROM inventory_sync_endpoint_attempts WHERE sync_run_id = :run AND endpoint_id = :endpoint AND outcome = 'selected'",
            ['run' => $inventory->runId->binary(), 'endpoint' => $inventory->endpointId->binary()],
        );
        if (1 !== $this->count($selected)) {
            throw new PbsInventoryConflict('The PBS inventory endpoint was not selected by its run.');
        }
    }

    /** @return array<string, mixed> */
    private function lockAndAssertRun(
        Connection $connection,
        CollectorLease $lease,
        InventoryIdentifier $runId,
        InventoryIdentifier $connectionId,
    ): array {
        $run = $connection->fetchAssociative(
            'SELECT * FROM inventory_sync_runs WHERE id = :run AND connection_id = :connection FOR UPDATE',
            ['run' => $runId->binary(), 'connection' => $connectionId->binary()],
        );
        if (false === $run
            || ($run['status'] ?? null) !== 'running'
            || !is_string($run['cycle_token'] ?? null)
            || !hash_equals($lease->token->binary(), $run['cycle_token'])
            || $this->integer($run, 'collector_fencing_token') !== $lease->fencingToken) {
            throw new CollectorLeaseOwnershipLost('The PBS inventory run is not owned by this collector lease.');
        }
        return $run;
    }

    private function lockAndAssertConnection(Connection $connection, InventoryIdentifier $id, int $revision): void
    {
        if (!$this->lockConnectionAtRevision($connection, $id, $revision)) {
            throw PbsInventoryConflict::connectionChanged();
        }
    }

    private function lockConnectionAtRevision(Connection $connection, InventoryIdentifier $id, int $revision): bool
    {
        $row = $connection->fetchAssociative(
            'SELECT product, enabled, revision FROM proxmox_connections WHERE id = :id FOR UPDATE',
            ['id' => $id->binary()],
        );
        return false !== $row && ($row['product'] ?? null) === 'pbs'
            && 1 === $this->integer($row, 'enabled')
            && $revision === $this->integer($row, 'revision');
    }

    private function lockAndAssertFence(Connection $connection, CollectorLease $lease): void
    {
        $schedule = $connection->fetchAssociative(
            'SELECT * FROM collector_schedule WHERE schedule_name = :name FOR UPDATE',
            ['name' => self::SCHEDULE_NAME],
        );
        $cycle = $connection->fetchAssociative(
            'SELECT * FROM collector_cycles WHERE cycle_token = :token FOR UPDATE',
            ['token' => $lease->token->binary()],
        );
        $this->assertFenceRows($connection, $lease, $schedule, $cycle);
    }

    private function assertFenceBeforeCommit(Connection $connection, CollectorLease $lease): void
    {
        $schedule = $connection->fetchAssociative(
            'SELECT * FROM collector_schedule WHERE schedule_name = :name',
            ['name' => self::SCHEDULE_NAME],
        );
        $cycle = $connection->fetchAssociative(
            'SELECT * FROM collector_cycles WHERE cycle_token = :token',
            ['token' => $lease->token->binary()],
        );
        $this->assertFenceRows($connection, $lease, $schedule, $cycle);
    }

    /**
     * @param array<string, mixed>|false $schedule
     * @param array<string, mixed>|false $cycle
     */
    private function assertFenceRows(
        Connection $connection,
        CollectorLease $lease,
        array|false $schedule,
        array|false $cycle,
    ): void {
        $now = $this->databaseNow($connection);
        if (false === $schedule || false === $cycle
            || !is_string($schedule['lease_owner'] ?? null)
            || !hash_equals($lease->ownerId->bytes, $schedule['lease_owner'])
            || !is_string($schedule['lease_token'] ?? null)
            || !hash_equals($lease->token->binary(), $schedule['lease_token'])
            || $this->integer($schedule, 'lease_fencing_token') !== $lease->fencingToken
            || !is_string($schedule['lease_expires_at'] ?? null)
            || $this->parseDate($schedule['lease_expires_at']) <= $now
            || ($cycle['status'] ?? null) !== 'running'
            || !is_string($cycle['worker_instance_id'] ?? null)
            || !hash_equals($lease->ownerId->bytes, $cycle['worker_instance_id'])
            || ($cycle['worker_kind'] ?? null) !== 'collector'
            || $this->integer($cycle, 'fencing_token') !== $lease->fencingToken) {
            throw new CollectorLeaseOwnershipLost('The PBS inventory write lost its collector lease.');
        }
    }

    private function databaseNow(Connection $connection): DateTimeImmutable
    {
        $value = $connection->fetchOne('SELECT UTC_TIMESTAMP(6)');
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB did not return its UTC clock.');
        }
        return $this->parseDate($value);
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date) {
            throw new RuntimeException('MariaDB returned an invalid UTC timestamp.');
        }
        return $date;
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new RuntimeException('MariaDB returned an invalid integer.');
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid binary identifier.');
        }
        return $value;
    }

    private function count(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new RuntimeException('MariaDB returned an invalid count.');
    }
}
