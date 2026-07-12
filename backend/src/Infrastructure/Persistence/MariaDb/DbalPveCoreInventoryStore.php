<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\Pve\PveCoreApplyResult;
use App\Application\Inventory\Pve\PveCoreApplyStatus;
use App\Application\Inventory\Pve\PveCoreBindingKind;
use App\Application\Inventory\Pve\PveCoreInstallationBinding;
use App\Application\Inventory\Pve\PveCoreInventoryCommit;
use App\Application\Inventory\Pve\PveCoreInventoryConflict;
use App\Application\Inventory\Pve\PveCoreInventoryStore;
use App\Application\Inventory\Pve\PveCoreScopeResult;
use App\Application\Inventory\Pve\PveEndpointAttempt;
use App\Application\Inventory\Pve\PveInventoryCommit;
use App\Application\Inventory\Pve\PveNodeStorageScopeResult;
use App\Application\Inventory\Pve\PveNodeObservation;
use App\Application\Inventory\Pve\PveNodeStorageStateObservation;
use App\Application\Inventory\Pve\PveStorageObservation;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Inventory\Pve\PveSyncRunFailure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalPveCoreInventoryStore implements PveCoreInventoryStore
{
    private const string SCHEDULE_NAME = 'inventory';
    private const string SCOPE_KEY = '@installation';

    public function __construct(
        private Connection $connection,
        private InventoryIdentifierGenerator $identifierGenerator,
    ) {
    }

    public function beginRun(CollectorLease $lease, PveSyncRunStart $start): void
    {
        $this->connection->transactional(function (Connection $connection) use ($lease, $start): void {
            $this->lockAndAssertFence($connection, $lease);
            $this->lockAndAssertConnection(
                $connection,
                $start->connectionId,
                $start->expectedConnectionRevision,
            );
            $startedAt = $this->format($start->startedAt);
            $connection->insert('inventory_sync_runs', [
                'id' => $start->runId->binary(),
                'cycle_token' => $lease->token->binary(),
                'collector_fencing_token' => $lease->fencingToken,
                'connection_id' => $start->connectionId->binary(),
                'expected_connection_revision' => $start->expectedConnectionRevision,
                'status' => 'running',
                'authoritative' => 0,
                'started_at' => $startedAt,
                'heartbeat_at' => $startedAt,
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
                throw new PveCoreInventoryConflict('The inventory sync run already selected its endpoint.');
            }
            $endpoint = $connection->fetchAssociative(
                <<<'SQL'
                    SELECT id
                    FROM proxmox_connection_endpoints
                    WHERE connection_id = :connection_id AND id = :endpoint_id
                    FOR UPDATE
                    SQL,
                [
                    'connection_id' => $attempt->connectionId->binary(),
                    'endpoint_id' => $attempt->endpointId->binary(),
                ],
            );
            if (false === $endpoint) {
                throw new PveCoreInventoryConflict('The endpoint attempt does not belong to the sync connection.');
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
                throw new PveCoreInventoryConflict('The inventory sync run was already applied.');
            }
            $expectedRevision = $this->integer($run, 'expected_connection_revision');
            if ($expectedRevision !== $failure->expectedConnectionRevision) {
                throw new PveCoreInventoryConflict('The inventory failure revision differs from its sync run.');
            }
            $connectionChanged = !$this->lockConnectionAtRevision(
                $connection,
                $failure->connectionId,
                $expectedRevision,
            );

            $finishedAt = $this->format($failure->finishedAt);
            $updated = $connection->update('inventory_sync_runs', [
                'status' => 'failed',
                'authoritative' => 0,
                'heartbeat_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'error_code' => $connectionChanged
                    ? \App\Application\Inventory\Connection\ConnectionReadFailureCode::ConnectionChanged->value
                    : $failure->failureCode->value,
                'error_summary' => null,
            ], ['id' => $failure->runId->binary(), 'status' => 'running', 'applied_at' => null]);
            if (1 !== $updated) {
                throw new PveCoreInventoryConflict('The inventory sync run could not be finished exactly once.');
            }
            $this->assertFenceBeforeCommit($connection, $lease);
        });
    }

    public function apply(CollectorLease $lease, PveInventoryCommit $inventory): PveCoreApplyResult
    {
        return $this->connection->transactional(function (Connection $connection) use ($lease, $inventory): PveCoreApplyResult {
            $commit = $inventory->core;
            $this->lockAndAssertFence($connection, $lease);
            $run = $this->lockAndAssertRun($connection, $lease, $commit->runId, $commit->connectionId);
            if (null !== ($run['applied_at'] ?? null)) {
                throw new PveCoreInventoryConflict('The inventory sync run was already applied.');
            }
            $expectedRevision = $this->integer($run, 'expected_connection_revision');
            if ($expectedRevision !== $commit->expectedConnectionRevision) {
                throw new PveCoreInventoryConflict('The inventory commit revision differs from its sync run.');
            }
            $this->lockAndAssertConnection($connection, $commit->connectionId, $expectedRevision);
            $this->assertSelectedEndpoint($connection, $commit, $run);

            $binding = $this->bindingForUpdate($connection, $commit->connectionId);
            $diagnosticOnly = false;
            if (false === $binding) {
                if (!$inventory->isFullyAuthoritative()) {
                    $diagnosticOnly = true;
                } else {
                    $this->insertBinding($connection, $commit);
                }
            } else {
                $this->assertMatchingBinding($binding, $commit->binding);
                if ([] === $commit->nodes) {
                    $diagnosticOnly = true;
                }
            }

            $created = 0;
            $updated = 0;
            $archived = 0;
            if (!$diagnosticOnly) {
                [$created, $updated, $archived, $clusterId, $nodeIds] = $this->applyCore(
                    $connection,
                    $commit,
                    false !== $binding,
                    $inventory->isFullyAuthoritative(),
                );
                [$storageCreated, $storageUpdated, $storageArchived] = $this->applyStorage(
                    $connection,
                    $inventory,
                    $clusterId,
                    $nodeIds,
                );
                $created += $storageCreated;
                $updated += $storageUpdated;
                $archived += $storageArchived;
                $connection->update('proxmox_installation_bindings', [
                    'last_verified_run_id' => $commit->runId->binary(),
                    'last_verified_at' => $this->format($commit->observedAt),
                ], ['connection_id' => $commit->connectionId->binary()]);
            }

            $this->insertScopeResult($connection, $commit, $commit->topologyScope);
            $this->insertScopeResult($connection, $commit, $commit->guestScope);
            $this->insertScopeResult($connection, $commit, $inventory->storageScope);
            foreach ($inventory->nodeStorageScopes as $nodeScope) {
                $this->insertNodeStorageScopeResult($connection, $commit, $nodeScope);
            }
            $status = $inventory->overallStatus();
            $finishedAt = $this->format($commit->observedAt);
            $connection->update('inventory_sync_runs', [
                'endpoint_id' => $commit->endpointId->binary(),
                'status' => $status,
                'authoritative' => 'succeeded' === $status ? 1 : 0,
                'heartbeat_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'applied_at' => $finishedAt,
                'nodes_seen' => count($commit->nodes),
                'guests_seen' => count($commit->guests),
                'storages_seen' => count($inventory->storages),
                'objects_created' => $created,
                'objects_updated' => $updated,
                'objects_archived' => $archived,
            ], ['id' => $commit->runId->binary(), 'status' => 'running']);
            $this->assertFenceBeforeCommit($connection, $lease);

            return new PveCoreApplyResult(
                PveCoreApplyStatus::from($status),
                $created,
                $updated,
                $archived,
                $diagnosticOnly,
            );
        });
    }

    /** @return array{int, int, int, InventoryIdentifier, array<string, InventoryIdentifier>} */
    private function applyCore(
        Connection $connection,
        PveCoreInventoryCommit $commit,
        bool $bindingExisted,
        bool $authoritative,
    ): array
    {
        $created = 0;
        $updated = 0;
        $archived = 0;
        $cluster = $connection->fetchAssociative(
            'SELECT * FROM pve_clusters WHERE connection_id = :connection_id FOR UPDATE',
            ['connection_id' => $commit->connectionId->binary()],
        );
        if (false === $cluster) {
            $clusterId = $this->identifierGenerator->generate();
            $connection->insert('pve_clusters', [
                'id' => $clusterId->binary(),
                'connection_id' => $commit->connectionId->binary(),
                'external_name' => PveCoreBindingKind::Cluster === $commit->binding->kind ? $commit->binding->value : null,
                'topology' => $commit->binding->topology(),
                'inventory_state' => 'active',
                'first_seen_run_id' => $commit->runId->binary(),
                'last_seen_run_id' => $commit->runId->binary(),
                'first_seen_at' => $this->format($commit->observedAt),
                'last_seen_at' => $this->format($commit->observedAt),
            ]);
            ++$created;
        } else {
            $clusterId = new InventoryIdentifier($this->binary($cluster, 'id'));
            if ($bindingExisted) {
                $this->assertKnownClusterMembership($connection, $clusterId, $commit);
            }
            $connection->update('pve_clusters', [
                'external_name' => PveCoreBindingKind::Cluster === $commit->binding->kind ? $commit->binding->value : null,
                'topology' => $commit->binding->topology(),
                'inventory_state' => 'active',
                'last_seen_run_id' => $commit->runId->binary(),
                'last_seen_at' => $this->format($commit->observedAt),
                'archived_at' => null,
            ], ['id' => $clusterId->binary()]);
            ++$updated;
        }

        $nodeIds = [];
        foreach ($commit->nodes as $node) {
            [$nodeId, $wasCreated] = $this->upsertNode($connection, $commit, $clusterId, $node);
            $nodeIds[$node->name] = $nodeId;
            $wasCreated ? ++$created : ++$updated;
        }

        foreach ($commit->guests as $guest) {
            $guestRow = $connection->fetchAssociative(
                <<<'SQL'
                    SELECT * FROM guests
                    WHERE cluster_id = :cluster_id AND guest_type = :guest_type AND vmid = :vmid
                    FOR UPDATE
                    SQL,
                [
                    'cluster_id' => $clusterId->binary(),
                    'guest_type' => $guest->type->value,
                    'vmid' => $guest->vmid,
                ],
            );
            if (false === $guestRow) {
                $guestId = $this->identifierGenerator->generate();
                $connection->insert('guests', [
                    'id' => $guestId->binary(),
                    'connection_id' => $commit->connectionId->binary(),
                    'cluster_id' => $clusterId->binary(),
                    'guest_type' => $guest->type->value,
                    'vmid' => $guest->vmid,
                    'name' => $guest->name,
                    'is_template' => null === $guest->isTemplate ? null : (int) $guest->isTemplate,
                    'inventory_state' => 'active',
                    'first_seen_run_id' => $commit->runId->binary(),
                    'last_seen_run_id' => $commit->runId->binary(),
                    'first_seen_at' => $this->format($commit->observedAt),
                    'last_seen_at' => $this->format($commit->observedAt),
                ]);
                ++$created;
            } else {
                $guestId = new InventoryIdentifier($this->binary($guestRow, 'id'));
                $connection->update('guests', [
                    'name' => $guest->name,
                    'is_template' => null === $guest->isTemplate ? null : (int) $guest->isTemplate,
                    'inventory_state' => 'active',
                    'last_seen_run_id' => $commit->runId->binary(),
                    'last_seen_at' => $this->format($commit->observedAt),
                    'archived_at' => null,
                ], ['id' => $guestId->binary()]);
                ++$updated;
            }
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO guest_placements (
                        guest_id, connection_id, cluster_id, node_id, observed_at, sync_run_id
                    ) VALUES (
                        :guest_id, :connection_id, :cluster_id, :node_id, :observed_at, :sync_run_id
                    )
                    ON DUPLICATE KEY UPDATE
                        node_id = VALUES(node_id), observed_at = VALUES(observed_at), sync_run_id = VALUES(sync_run_id)
                    SQL,
                [
                    'guest_id' => $guestId->binary(),
                    'connection_id' => $commit->connectionId->binary(),
                    'cluster_id' => $clusterId->binary(),
                    'node_id' => $nodeIds[$guest->node]->binary(),
                    'observed_at' => $this->format($commit->observedAt),
                    'sync_run_id' => $commit->runId->binary(),
                ],
            );
        }

        if ($authoritative) {
            $unseenGuests = $connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT id FROM guests
                    WHERE cluster_id = :cluster_id AND inventory_state = 'active' AND last_seen_run_id <> :run_id
                    ORDER BY guest_type, vmid
                    FOR UPDATE
                    SQL,
                ['cluster_id' => $clusterId->binary(), 'run_id' => $commit->runId->binary()],
            );
            foreach ($unseenGuests as $row) {
                $guestId = $this->binary($row, 'id');
                $connection->delete('guest_placements', ['guest_id' => $guestId]);
                $connection->update('guests', [
                    'inventory_state' => 'archived',
                    'archived_at' => $this->format($commit->observedAt),
                ], ['id' => $guestId, 'inventory_state' => 'active']);
                ++$archived;
            }

            $unseenNodes = $connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT id FROM pve_nodes
                    WHERE cluster_id = :cluster_id AND inventory_state = 'active' AND last_seen_run_id <> :run_id
                    ORDER BY node_name
                    FOR UPDATE
                    SQL,
                ['cluster_id' => $clusterId->binary(), 'run_id' => $commit->runId->binary()],
            );
            foreach ($unseenNodes as $row) {
                $connection->update('pve_nodes', [
                    'inventory_state' => 'archived',
                    'archived_at' => $this->format($commit->observedAt),
                ], ['id' => $this->binary($row, 'id'), 'inventory_state' => 'active']);
                ++$archived;
            }
        }

        return [$created, $updated, $archived, $clusterId, $nodeIds];
    }

    /**
     * @param array<string, InventoryIdentifier> $nodeIds
     *
     * @return array{int, int, int}
     */
    private function applyStorage(
        Connection $connection,
        PveInventoryCommit $inventory,
        InventoryIdentifier $clusterId,
        array $nodeIds,
    ): array {
        $created = 0;
        $updated = 0;
        $archived = 0;
        $storageIds = [];
        foreach ($inventory->storages as $storage) {
            [$storageId, $wasCreated] = $this->upsertStorage($connection, $inventory, $clusterId, $storage);
            $storageIds[$storage->storageId] = $storageId;
            $wasCreated ? ++$created : ++$updated;
            $this->replacePbsMapping($connection, $inventory, $clusterId, $storageId, $storage);
            if ($storage->disabled) {
                $connection->delete('pve_node_storage_state', ['storage_id' => $storageId->binary()]);
            }
        }

        foreach ($inventory->nodeStorageStates as $state) {
            // PveInventoryCommit has already proved that every state belongs
            // to one of its observed topology nodes and backup storages.
            $this->upsertNodeStorageState(
                $connection,
                $inventory,
                $clusterId,
                $nodeIds[$state->node],
                $storageIds[$state->storageId],
                $state,
            );
        }

        if ($inventory->isFullyAuthoritative()) {
            $connection->executeStatement(
                <<<'SQL'
                    DELETE FROM pve_node_storage_state
                    WHERE connection_id = :connection_id AND cluster_id = :cluster_id
                      AND sync_run_id <> :run_id
                    SQL,
                [
                    'connection_id' => $inventory->core->connectionId->binary(),
                    'cluster_id' => $clusterId->binary(),
                    'run_id' => $inventory->core->runId->binary(),
                ],
            );
            $unseen = $connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT id FROM pve_storages
                    WHERE cluster_id = :cluster_id AND inventory_state = 'active' AND last_seen_run_id <> :run_id
                    ORDER BY storage_name
                    FOR UPDATE
                    SQL,
                ['cluster_id' => $clusterId->binary(), 'run_id' => $inventory->core->runId->binary()],
            );
            foreach ($unseen as $row) {
                $storageId = $this->binary($row, 'id');
                $connection->delete('pve_storage_pbs_mappings', ['storage_id' => $storageId]);
                $connection->delete('pve_node_storage_state', ['storage_id' => $storageId]);
                $connection->update('pve_storages', [
                    'inventory_state' => 'archived',
                    'archived_at' => $this->format($inventory->core->observedAt),
                ], ['id' => $storageId, 'inventory_state' => 'active']);
                ++$archived;
            }
        }

        return [$created, $updated, $archived];
    }

    /** @return array{InventoryIdentifier, bool} */
    private function upsertStorage(
        Connection $connection,
        PveInventoryCommit $inventory,
        InventoryIdentifier $clusterId,
        PveStorageObservation $storage,
    ): array {
        $row = $connection->fetchAssociative(
            'SELECT * FROM pve_storages WHERE cluster_id = :cluster_id AND storage_name = :name FOR UPDATE',
            ['cluster_id' => $clusterId->binary(), 'name' => $storage->storageId],
        );
        $values = [
            'storage_type' => $storage->storageType,
            'supports_backup' => 1,
            'disabled' => (int) $storage->disabled,
            'content_json' => $this->json($storage->content),
            'node_allowlist_json' => null === $storage->nodeAllowlist ? null : $this->json($storage->nodeAllowlist),
            'shared' => (int) $storage->shared,
            'inventory_state' => 'active',
            'last_seen_run_id' => $inventory->core->runId->binary(),
            'last_seen_at' => $this->format($storage->observedAt),
            'archived_at' => null,
        ];
        if (false === $row) {
            $storageId = $this->identifierGenerator->generate();
            $connection->insert('pve_storages', [
                'id' => $storageId->binary(),
                'connection_id' => $inventory->core->connectionId->binary(),
                'cluster_id' => $clusterId->binary(),
                'storage_name' => $storage->storageId,
                'first_seen_run_id' => $inventory->core->runId->binary(),
                'first_seen_at' => $this->format($storage->observedAt),
                ...$values,
            ]);

            return [$storageId, true];
        }

        $storageId = new InventoryIdentifier($this->binary($row, 'id'));
        $connection->update('pve_storages', $values, ['id' => $storageId->binary()]);

        return [$storageId, false];
    }

    private function replacePbsMapping(
        Connection $connection,
        PveInventoryCommit $inventory,
        InventoryIdentifier $clusterId,
        InventoryIdentifier $storageId,
        PveStorageObservation $storage,
    ): void {
        if (null === $storage->pbsMapping) {
            $connection->delete('pve_storage_pbs_mappings', ['storage_id' => $storageId->binary()]);

            return;
        }
        $mapping = $storage->pbsMapping;
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO pve_storage_pbs_mappings (
                    storage_id, connection_id, cluster_id, server, port, datastore, namespace,
                    observed_at, sync_run_id
                ) VALUES (
                    :storage_id, :connection_id, :cluster_id, :server, :port, :datastore, :namespace,
                    :observed_at, :sync_run_id
                )
                ON DUPLICATE KEY UPDATE
                    server = VALUES(server), port = VALUES(port), datastore = VALUES(datastore),
                    namespace = VALUES(namespace), observed_at = VALUES(observed_at),
                    sync_run_id = VALUES(sync_run_id)
                SQL,
            [
                'storage_id' => $storageId->binary(),
                'connection_id' => $inventory->core->connectionId->binary(),
                'cluster_id' => $clusterId->binary(),
                'server' => $mapping->server,
                'port' => $mapping->port,
                'datastore' => $mapping->datastore,
                'namespace' => $mapping->namespace,
                'observed_at' => $this->format($storage->observedAt),
                'sync_run_id' => $inventory->core->runId->binary(),
            ],
        );
    }

    private function upsertNodeStorageState(
        Connection $connection,
        PveInventoryCommit $inventory,
        InventoryIdentifier $clusterId,
        InventoryIdentifier $nodeId,
        InventoryIdentifier $storageId,
        PveNodeStorageStateObservation $state,
    ): void {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO pve_node_storage_state (
                    connection_id, cluster_id, node_id, storage_id, enabled, active, shared,
                    capacity_status, total_bytes, used_bytes, available_bytes, observed_at, sync_run_id
                ) VALUES (
                    :connection_id, :cluster_id, :node_id, :storage_id, :enabled, :active, :shared,
                    :capacity_status, :total_bytes, :used_bytes, :available_bytes, :observed_at, :sync_run_id
                )
                ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled), active = VALUES(active), shared = VALUES(shared),
                    capacity_status = VALUES(capacity_status), total_bytes = VALUES(total_bytes),
                    used_bytes = VALUES(used_bytes), available_bytes = VALUES(available_bytes),
                    observed_at = VALUES(observed_at), sync_run_id = VALUES(sync_run_id)
                SQL,
            [
                'connection_id' => $inventory->core->connectionId->binary(),
                'cluster_id' => $clusterId->binary(),
                'node_id' => $nodeId->binary(),
                'storage_id' => $storageId->binary(),
                'enabled' => (int) $state->enabled,
                'active' => (int) $state->active,
                'shared' => (int) $state->shared,
                'capacity_status' => $state->capacityStatus->value,
                'total_bytes' => $state->totalBytes,
                'used_bytes' => $state->usedBytes,
                'available_bytes' => $state->availableBytes,
                'observed_at' => $this->format($state->observedAt),
                'sync_run_id' => $inventory->core->runId->binary(),
            ],
        );
    }

    /** @return array{InventoryIdentifier, bool} */
    private function upsertNode(
        Connection $connection,
        PveCoreInventoryCommit $commit,
        InventoryIdentifier $clusterId,
        PveNodeObservation $node,
    ): array {
        $row = $connection->fetchAssociative(
            'SELECT * FROM pve_nodes WHERE cluster_id = :cluster_id AND node_name = :node_name FOR UPDATE',
            ['cluster_id' => $clusterId->binary(), 'node_name' => $node->name],
        );
        if (false === $row) {
            $nodeId = $this->identifierGenerator->generate();
            $connection->insert('pve_nodes', [
                'id' => $nodeId->binary(),
                'connection_id' => $commit->connectionId->binary(),
                'cluster_id' => $clusterId->binary(),
                'node_name' => $node->name,
                'api_status' => $node->apiStatus,
                'inventory_state' => 'active',
                'first_seen_run_id' => $commit->runId->binary(),
                'last_seen_run_id' => $commit->runId->binary(),
                'first_seen_at' => $this->format($commit->observedAt),
                'last_seen_at' => $this->format($commit->observedAt),
            ]);
            return [$nodeId, true];
        }

        $nodeId = new InventoryIdentifier($this->binary($row, 'id'));
        $connection->update('pve_nodes', [
            'api_status' => $node->apiStatus,
            'inventory_state' => 'active',
            'last_seen_run_id' => $commit->runId->binary(),
            'last_seen_at' => $this->format($commit->observedAt),
            'archived_at' => null,
        ], ['id' => $nodeId->binary()]);
        return [$nodeId, false];
    }

    private function assertKnownClusterMembership(
        Connection $connection,
        InventoryIdentifier $clusterId,
        PveCoreInventoryCommit $commit,
    ): void {
        if (PveCoreBindingKind::Standalone === $commit->binding->kind) {
            return;
        }
        $known = $connection->fetchFirstColumn(
            'SELECT node_name FROM pve_nodes WHERE cluster_id = :cluster_id ORDER BY node_name FOR UPDATE',
            ['cluster_id' => $clusterId->binary()],
        );
        $knownNames = [];
        foreach ($known as $name) {
            if (is_string($name)) {
                $knownNames[$name] = true;
            }
        }
        foreach ($commit->nodes as $node) {
            if (isset($knownNames[$node->name])) {
                return;
            }
        }
        throw new PveCoreInventoryConflict('The clustered PVE snapshot has no known member node.');
    }

    private function insertBinding(Connection $connection, PveCoreInventoryCommit $commit): void
    {
        $at = $this->format($commit->observedAt);
        $connection->insert('proxmox_installation_bindings', [
            'connection_id' => $commit->connectionId->binary(),
            'product' => 'pve',
            'identity_kind' => $commit->binding->kind->value,
            'identity_value' => $commit->binding->value,
            'first_bound_run_id' => $commit->runId->binary(),
            'last_verified_run_id' => $commit->runId->binary(),
            'first_bound_at' => $at,
            'last_verified_at' => $at,
        ]);
    }

    /** @param array<string, mixed> $binding */
    private function assertMatchingBinding(array $binding, PveCoreInstallationBinding $expected): void
    {
        if (($binding['product'] ?? null) !== 'pve'
            || ($binding['identity_kind'] ?? null) !== $expected->kind->value
            || ($binding['identity_value'] ?? null) !== $expected->value) {
            throw new PveCoreInventoryConflict('The PVE installation binding does not match this connection.');
        }
    }

    private function insertScopeResult(
        Connection $connection,
        PveCoreInventoryCommit $commit,
        PveCoreScopeResult $scope,
    ): void {
        $connection->insert('inventory_sync_scope_results', [
            'connection_id' => $commit->connectionId->binary(),
            'sync_run_id' => $commit->runId->binary(),
            'scope_type' => $scope->scope->value,
            'scope_key' => self::SCOPE_KEY,
            'status' => $scope->status->value,
            'observed_at' => $this->format($commit->observedAt),
        ]);
    }

    private function insertNodeStorageScopeResult(
        Connection $connection,
        PveCoreInventoryCommit $commit,
        PveNodeStorageScopeResult $scope,
    ): void {
        $connection->insert('inventory_sync_scope_results', [
            'connection_id' => $commit->connectionId->binary(),
            'sync_run_id' => $commit->runId->binary(),
            'scope_type' => \App\Application\Inventory\Pve\PveCoreScope::NodeStorages->value,
            'scope_key' => $scope->node,
            'status' => $scope->status->value,
            'observed_at' => $this->format($commit->observedAt),
        ]);
    }

    /** @param array<string, mixed> $run */
    private function assertSelectedEndpoint(
        Connection $connection,
        PveCoreInventoryCommit $commit,
        array $run,
    ): void
    {
        $runEndpoint = $run['endpoint_id'] ?? null;
        if (!is_string($runEndpoint)
            || 16 !== strlen($runEndpoint)
            || !hash_equals($commit->endpointId->binary(), $runEndpoint)) {
            throw new PveCoreInventoryConflict('The inventory commit endpoint differs from the sync run selection.');
        }
        $selected = $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM inventory_sync_endpoint_attempts
                WHERE sync_run_id = :run_id AND endpoint_id = :endpoint_id AND outcome = 'selected'
                SQL,
            ['run_id' => $commit->runId->binary(), 'endpoint_id' => $commit->endpointId->binary()],
        );
        if (1 !== $this->count($selected)) {
            throw new PveCoreInventoryConflict('The inventory commit endpoint was not selected by this sync run.');
        }
    }

    /** @return array<string, mixed>|false */
    private function bindingForUpdate(Connection $connection, InventoryIdentifier $connectionId): array|false
    {
        return $connection->fetchAssociative(
            'SELECT * FROM proxmox_installation_bindings WHERE connection_id = :connection_id FOR UPDATE',
            ['connection_id' => $connectionId->binary()],
        );
    }

    /** @return array<string, mixed> */
    private function lockAndAssertRun(
        Connection $connection,
        CollectorLease $lease,
        InventoryIdentifier $runId,
        InventoryIdentifier $connectionId,
    ): array {
        $run = $connection->fetchAssociative(
            <<<'SQL'
                SELECT * FROM inventory_sync_runs
                WHERE id = :run_id AND connection_id = :connection_id
                FOR UPDATE
                SQL,
            ['run_id' => $runId->binary(), 'connection_id' => $connectionId->binary()],
        );
        if (false === $run
            || ($run['status'] ?? null) !== 'running'
            || !is_string($run['cycle_token'] ?? null)
            || !hash_equals($lease->token->binary(), $run['cycle_token'])
            || $this->integer($run, 'collector_fencing_token') !== $lease->fencingToken) {
            throw new CollectorLeaseOwnershipLost('The inventory sync run is not owned by this collector lease.');
        }
        return $run;
    }

    private function lockAndAssertConnection(
        Connection $connection,
        InventoryIdentifier $connectionId,
        int $expectedRevision,
    ): void {
        if (!$this->lockConnectionAtRevision($connection, $connectionId, $expectedRevision)) {
            throw PveCoreInventoryConflict::connectionChanged();
        }
    }

    private function lockConnectionAtRevision(
        Connection $connection,
        InventoryIdentifier $connectionId,
        int $expectedRevision,
    ): bool {
        $row = $connection->fetchAssociative(
            'SELECT product, enabled, revision FROM proxmox_connections WHERE id = :id FOR UPDATE',
            ['id' => $connectionId->binary()],
        );
        return !(false === $row
            || ($row['product'] ?? null) !== 'pve'
            || 1 !== $this->integer($row, 'enabled')
            || $expectedRevision !== $this->integer($row, 'revision'));
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
        $databaseNow = $this->databaseNow($connection);
        if (false === $schedule || false === $cycle
            || !is_string($schedule['lease_owner'] ?? null)
            || !hash_equals($lease->ownerId->bytes, $schedule['lease_owner'])
            || !is_string($schedule['lease_token'] ?? null)
            || !hash_equals($lease->token->binary(), $schedule['lease_token'])
            || $this->integer($schedule, 'lease_fencing_token') !== $lease->fencingToken
            || !is_string($schedule['lease_expires_at'] ?? null)
            || $this->parseDate($schedule['lease_expires_at']) <= $databaseNow
            || ($cycle['status'] ?? null) !== 'running'
            || !is_string($cycle['worker_instance_id'] ?? null)
            || !hash_equals($lease->ownerId->bytes, $cycle['worker_instance_id'])
            || ($cycle['worker_kind'] ?? null) !== 'collector'
            || $this->integer($cycle, 'fencing_token') !== $lease->fencingToken) {
            throw new CollectorLeaseOwnershipLost('The PVE inventory write lost its collector lease.');
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

    /** @param list<string> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
