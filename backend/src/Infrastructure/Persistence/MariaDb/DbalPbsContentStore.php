<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\PbsContent\PbsContentApplyResult;
use App\Application\Inventory\PbsContent\PbsContentCommit;
use App\Application\Inventory\PbsContent\PbsContentConflict;
use App\Application\Inventory\PbsContent\PbsContentRunFailure;
use App\Application\Inventory\PbsContent\PbsContentRunStart;
use App\Application\Inventory\PbsContent\PbsContentRunStatus;
use App\Application\Inventory\PbsContent\PbsContentScopeType;
use App\Application\Inventory\PbsContent\PbsContentStore;
use App\Application\Inventory\PbsContent\PbsNamespaceObservation;
use App\Application\Proxmox\Pbs\PbsSnapshotObservation;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalPbsContentStore implements PbsContentStore
{
    private const string SCHEDULE_NAME = 'inventory';

    public function __construct(
        private Connection $connection,
        private InventoryIdentifierGenerator $identifierGenerator,
    ) {}

    public function begin(CollectorLease $lease, PbsContentRunStart $start): void
    {
        $this->connection->transactional(function (Connection $connection) use ($lease, $start): void {
            $this->lockAndAssertFence($connection, $lease);
            $this->assertUsableParent($connection, $lease, $start);
            $this->assertConnectionBindingAndEndpoint($connection, $start);
            $at = $this->format($start->startedAt);
            try {
                $connection->insert('pbs_content_runs', [
                    'id' => $start->runId->binary(),
                    'parent_run_id' => $start->parentRunId->binary(),
                    'cycle_token' => $lease->token->binary(),
                    'collector_fencing_token' => $lease->fencingToken,
                    'connection_id' => $start->connectionId->binary(),
                    'endpoint_id' => $start->endpointId->bytes,
                    'expected_connection_revision' => $start->expectedConnectionRevision,
                    'status' => PbsContentRunStatus::Running->value,
                    'started_at' => $at,
                    'heartbeat_at' => $at,
                ]);
            } catch (\Throwable $failure) {
                throw new PbsContentConflict('The PBS content run could not be started exactly once.', 0, $failure);
            }
            $this->assertFenceBeforeCommit($connection, $lease);
        });
    }

    public function fail(CollectorLease $lease, PbsContentRunFailure $failure): void
    {
        $this->connection->transactional(function (Connection $connection) use ($lease, $failure): void {
            $this->lockAndAssertFence($connection, $lease);
            $this->lockRun($connection, $lease, $failure->runId, $failure->connectionId);
            $at = $this->format($failure->finishedAt);
            $changed = $connection->update('pbs_content_runs', [
                'status' => PbsContentRunStatus::Failed->value,
                'heartbeat_at' => $at,
                'finished_at' => $at,
                'error_code' => $failure->errorCode,
            ], ['id' => $failure->runId->binary(), 'status' => 'running', 'applied_at' => null]);
            if (1 !== $changed) {
                throw new PbsContentConflict('The PBS content run could not be failed exactly once.');
            }
            $this->assertFenceBeforeCommit($connection, $lease);
        });
    }

    public function apply(CollectorLease $lease, PbsContentCommit $commit): PbsContentApplyResult
    {
        if (PbsContentRunStatus::Failed === $commit->snapshot->status()) {
            throw new PbsContentConflict('A PBS content commit requires at least one usable scope.');
        }
        return $this->connection->transactional(function (Connection $connection) use ($lease, $commit): PbsContentApplyResult {
            $this->lockAndAssertFence($connection, $lease);
            $run = $this->lockRun($connection, $lease, $commit->runId, $commit->connectionId);
            $this->assertCommitAndCurrentState($connection, $lease, $run, $commit);

            $datastores = $this->lockDatastores($connection, $commit);
            $created = 0;
            $updated = 0;
            $archived = 0;
            /** @var array<string, InventoryIdentifier> $namespaceIds */
            $namespaceIds = [];
            foreach ($commit->snapshot->namespaces as $namespace) {
                $datastoreId = $datastores[$namespace->datastore->value] ?? null;
                if (null === $datastoreId) {
                    throw new PbsContentConflict('A PBS content namespace references an unavailable datastore.');
                }
                [$namespaceId, $wasCreated] = $this->upsertNamespace(
                    $connection, $commit, $datastoreId, $namespace,
                );
                $namespaceIds[$namespace->key()] = $namespaceId;
                $wasCreated ? ++$created : ++$updated;
            }
            $this->linkParents($connection, $commit, $datastores, $namespaceIds);

            foreach ($commit->snapshot->snapshots as $snapshot) {
                $namespaceKey = $snapshot->datastore->value."\0".$snapshot->namespace->value;
                $namespaceId = $namespaceIds[$namespaceKey] ?? null;
                if (null === $namespaceId) {
                    throw new PbsContentConflict('A PBS snapshot references an unavailable namespace.');
                }
                [$groupId, $groupCreated] = $this->upsertGroup($connection, $commit, $namespaceId, $snapshot);
                $groupCreated ? ++$created : ++$updated;
                $this->upsertSnapshot($connection, $commit, $groupId, $snapshot) ? ++$created : ++$updated;
            }

            foreach ($commit->snapshot->scopes as $scope) {
                $this->insertScope($connection, $commit, $scope);
                if (!$scope->permitsAbsenceDecisions()) {
                    continue;
                }
                $datastoreId = $datastores[$scope->datastore->value] ?? null;
                if (null === $datastoreId) {
                    throw new PbsContentConflict('A PBS content scope references an unavailable datastore.');
                }
                $namespaceId = $namespaceIds[$scope->datastore->value."\0".$scope->namespace?->value] ?? null;
                if (null === $namespaceId) {
                    throw new PbsContentConflict('A complete snapshot scope requires its namespace observation.');
                }
                $archived += $this->archiveSnapshotsAndGroups($connection, $commit, $namespaceId);
            }

            $status = $commit->snapshot->status();
            $at = $this->format($commit->observedAt);
            $changed = $connection->update('pbs_content_runs', [
                'status' => $status->value,
                'namespaces_seen' => count($commit->snapshot->namespaces),
                'snapshots_seen' => count($commit->snapshot->snapshots),
                'objects_created' => $created,
                'objects_updated' => $updated,
                'objects_archived' => $archived,
                'heartbeat_at' => $at,
                'finished_at' => $at,
                'applied_at' => $at,
            ], ['id' => $commit->runId->binary(), 'status' => 'running', 'applied_at' => null]);
            if (1 !== $changed) {
                throw new PbsContentConflict('The PBS content run could not be applied exactly once.');
            }
            $this->assertFenceBeforeCommit($connection, $lease);
            return new PbsContentApplyResult($status, $created, $updated, $archived);
        });
    }

    /** @return array<string, InventoryIdentifier> */
    private function lockDatastores(Connection $connection, PbsContentCommit $commit): array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT id, datastore_name FROM pbs_datastores WHERE connection_id = :connection AND inventory_state = \'active\' ORDER BY datastore_name FOR UPDATE',
            ['connection' => $commit->connectionId->binary()],
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$this->text($row, 'datastore_name')] = new InventoryIdentifier($this->binary($row, 'id'));
        }
        return $map;
    }

    /** @return array{InventoryIdentifier, bool} */
    private function upsertNamespace(
        Connection $connection,
        PbsContentCommit $commit,
        InventoryIdentifier $datastoreId,
        PbsNamespaceObservation $observation,
    ): array {
        $row = $connection->fetchAssociative(
            'SELECT id FROM pbs_namespaces WHERE datastore_id = :datastore AND namespace_path = :path FOR UPDATE',
            ['datastore' => $datastoreId->binary(), 'path' => $observation->namespace->value],
        );
        $at = $this->format($commit->observedAt);
        $values = [
            'namespace_depth' => $observation->namespace->depth(),
            'inventory_state' => 'active',
            'last_seen_run_id' => $commit->runId->binary(),
            'last_seen_at' => $at,
            'archived_at' => null,
        ];
        if (false === $row) {
            $server = $connection->fetchAssociative(
                'SELECT server_id FROM pbs_datastores WHERE id = :id',
                ['id' => $datastoreId->binary()],
            );
            if (false === $server) {
                throw new PbsContentConflict('The PBS content datastore disappeared during apply.');
            }
            $id = $this->identifierGenerator->generate();
            $connection->insert('pbs_namespaces', [
                'id' => $id->binary(),
                'connection_id' => $commit->connectionId->binary(),
                'server_id' => $this->binary($server, 'server_id'),
                'datastore_id' => $datastoreId->binary(),
                'namespace_path' => $observation->namespace->value,
                'parent_namespace_id' => null,
                'first_seen_run_id' => $commit->runId->binary(),
                'first_seen_at' => $at,
                ...$values,
            ]);
            return [$id, true];
        }
        $id = new InventoryIdentifier($this->binary($row, 'id'));
        $connection->update('pbs_namespaces', $values, ['id' => $id->binary()]);
        return [$id, false];
    }

    /** @param array<string, InventoryIdentifier> $datastores
     *  @param array<string, InventoryIdentifier> $namespaceIds
     */
    private function linkParents(
        Connection $connection,
        PbsContentCommit $commit,
        array $datastores,
        array $namespaceIds,
    ): void {
        foreach ($commit->snapshot->namespaces as $observation) {
            $parent = $observation->namespace->parent();
            $parentId = null === $parent ? null
                : ($namespaceIds[$observation->datastore->value."\0".$parent->value] ?? null);
            $connection->update('pbs_namespaces', [
                'parent_namespace_id' => $parentId?->binary(),
            ], ['id' => $namespaceIds[$observation->key()]->binary()]);
        }
    }

    /** @return array{InventoryIdentifier, bool} */
    private function upsertGroup(
        Connection $connection,
        PbsContentCommit $commit,
        InventoryIdentifier $namespaceId,
        PbsSnapshotObservation $snapshot,
    ): array {
        $row = $connection->fetchAssociative(
            'SELECT id, owner_auth_id FROM pbs_backup_groups WHERE namespace_id = :namespace AND backup_type = :type AND backup_id = :backup FOR UPDATE',
            ['namespace' => $namespaceId->binary(), 'type' => $snapshot->backupType->value, 'backup' => $snapshot->backupId],
        );
        $at = $this->format($commit->observedAt);
        $values = [
            'owner_auth_id' => $snapshot->owner,
            'inventory_state' => 'active',
            'last_seen_run_id' => $commit->runId->binary(),
            'last_seen_at' => $at,
            'archived_at' => null,
        ];
        if (false === $row) {
            $id = $this->identifierGenerator->generate();
            $connection->insert('pbs_backup_groups', [
                'id' => $id->binary(),
                'connection_id' => $commit->connectionId->binary(),
                'namespace_id' => $namespaceId->binary(),
                'backup_type' => $snapshot->backupType->value,
                'backup_id' => $snapshot->backupId,
                'first_seen_run_id' => $commit->runId->binary(),
                'first_seen_at' => $at,
                ...$values,
            ]);
            return [$id, true];
        }
        $id = new InventoryIdentifier($this->binary($row, 'id'));
        if (null === $snapshot->owner) {
            unset($values['owner_auth_id']);
        } elseif (is_string($row['owner_auth_id'] ?? null)
            && !hash_equals($snapshot->owner, $row['owner_auth_id'])) {
            throw new PbsContentConflict('The PBS backup group owner changed unexpectedly.');
        }
        $connection->update('pbs_backup_groups', $values, ['id' => $id->binary()]);
        return [$id, false];
    }

    private function upsertSnapshot(
        Connection $connection,
        PbsContentCommit $commit,
        InventoryIdentifier $groupId,
        PbsSnapshotObservation $snapshot,
    ): bool {
        $backupTime = $this->format($snapshot->backupTime);
        $row = $connection->fetchAssociative(
            'SELECT id FROM pbs_snapshots WHERE group_id = :group AND backup_time = :time FOR UPDATE',
            ['group' => $groupId->binary(), 'time' => $backupTime],
        );
        $at = $this->format($commit->observedAt);
        $values = [
            'protected' => (int) $snapshot->protected,
            'size_bytes' => $snapshot->size,
            'comment' => $snapshot->comment,
            'encryption_fingerprint' => $snapshot->fingerprint,
            'verification_state' => $snapshot->verification?->state,
            'verification_upid' => $snapshot->verification?->upid->value,
            'files_json' => json_encode($snapshot->files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'inventory_state' => 'active',
            'last_seen_run_id' => $commit->runId->binary(),
            'last_seen_at' => $at,
            'archived_at' => null,
        ];
        if (false === $row) {
            $id = $this->identifierGenerator->generate();
            $connection->insert('pbs_snapshots', [
                'id' => $id->binary(),
                'connection_id' => $commit->connectionId->binary(),
                'group_id' => $groupId->binary(),
                'backup_time' => $backupTime,
                'first_seen_run_id' => $commit->runId->binary(),
                'first_seen_at' => $at,
                ...$values,
            ]);
            return true;
        }
        $connection->update('pbs_snapshots', $values, ['id' => $this->binary($row, 'id')]);
        return false;
    }

    private function insertScope(
        Connection $connection,
        PbsContentCommit $commit,
        \App\Application\Inventory\PbsContent\PbsContentScopeResult $scope,
    ): void {
        $connection->insert('pbs_content_scope_results', [
            'id' => $this->identifierGenerator->generate()->binary(),
            'connection_id' => $commit->connectionId->binary(),
            'content_run_id' => $commit->runId->binary(),
            'datastore_name' => $scope->datastore->value,
            'scope_type' => $scope->type->value,
            'namespace_path' => PbsContentScopeType::Namespaces === $scope->type
                ? '@namespaces' : $scope->namespace?->value,
            'status' => $scope->status->value,
            'rows_read' => $scope->rowsRead,
            'error_code' => $scope->errorCode,
        ]);
    }

    private function archiveSnapshotsAndGroups(
        Connection $connection,
        PbsContentCommit $commit,
        InventoryIdentifier $namespaceId,
    ): int {
        $at = $this->format($commit->observedAt);
        $snapshotCount = $connection->executeStatement(
            'UPDATE pbs_snapshots s JOIN pbs_backup_groups g ON g.id = s.group_id SET s.inventory_state = \'archived\', s.archived_at = :at WHERE g.namespace_id = :namespace AND s.inventory_state = \'active\' AND s.last_seen_run_id <> :run',
            ['at' => $at, 'namespace' => $namespaceId->binary(), 'run' => $commit->runId->binary()],
        );
        $groupCount = $connection->executeStatement(
            'UPDATE pbs_backup_groups g SET g.inventory_state = \'archived\', g.archived_at = :at WHERE g.namespace_id = :namespace AND g.inventory_state = \'active\' AND NOT EXISTS (SELECT 1 FROM pbs_snapshots s WHERE s.group_id = g.id AND s.inventory_state = \'active\')',
            ['at' => $at, 'namespace' => $namespaceId->binary()],
        );
        return (int) ($snapshotCount + $groupCount);
    }

    private function assertUsableParent(Connection $connection, CollectorLease $lease, PbsContentRunStart $start): void
    {
        $parent = $connection->fetchAssociative(
            'SELECT * FROM inventory_sync_runs WHERE id = :run AND connection_id = :connection FOR UPDATE',
            ['run' => $start->parentRunId->binary(), 'connection' => $start->connectionId->binary()],
        );
        if (false === $parent || !in_array($parent['status'] ?? null, ['succeeded', 'partial'], true)
            || !is_string($parent['applied_at'] ?? null)
            || !is_string($parent['endpoint_id'] ?? null)
            || !hash_equals($start->endpointId->bytes, $parent['endpoint_id'])
            || $this->integer($parent, 'expected_connection_revision') !== $start->expectedConnectionRevision
            || !is_string($parent['cycle_token'] ?? null)
            || !hash_equals($lease->token->binary(), $parent['cycle_token'])
            || $this->integer($parent, 'collector_fencing_token') !== $lease->fencingToken) {
            throw new PbsContentConflict('The PBS content parent run is not usable.');
        }
    }

    private function assertConnectionBindingAndEndpoint(Connection $connection, PbsContentRunStart $start): void
    {
        $current = $connection->fetchAssociative(
            'SELECT product, enabled, revision FROM proxmox_connections WHERE id = :id FOR UPDATE',
            ['id' => $start->connectionId->binary()],
        );
        $binding = $connection->fetchAssociative(
            'SELECT product, identity_kind, identity_value, legacy_endpoint_id FROM proxmox_installation_bindings WHERE connection_id = :id FOR UPDATE',
            ['id' => $start->connectionId->binary()],
        );
        $selected = $connection->fetchOne(
            'SELECT COUNT(*) FROM inventory_sync_endpoint_attempts WHERE sync_run_id = :run AND endpoint_id = :endpoint AND outcome = \'selected\'',
            ['run' => $start->parentRunId->binary(), 'endpoint' => $start->endpointId->bytes],
        );
        if (false === $current || ($current['product'] ?? null) !== 'pbs'
            || 1 !== $this->integer($current, 'enabled')
            || $this->integer($current, 'revision') !== $start->expectedConnectionRevision
            || false === $binding || ($binding['product'] ?? null) !== 'pbs'
            || ($binding['identity_kind'] ?? null) !== $start->binding->kind->value
            || !is_string($binding['identity_value'] ?? null)
            || !hash_equals($start->binding->identity, $binding['identity_value'])
            || !$this->sameNullable($start->binding->legacyEndpointId?->bytes, $binding['legacy_endpoint_id'] ?? null)
            || 1 !== $this->count($selected)) {
            throw new PbsContentConflict('The PBS content connection, binding, or endpoint changed.');
        }
    }

    /** @param array<string, mixed> $run */
    private function assertCommitAndCurrentState(
        Connection $connection,
        CollectorLease $lease,
        array $run,
        PbsContentCommit $commit,
    ): void {
        if (!hash_equals($commit->parentRunId->binary(), $this->binary($run, 'parent_run_id'))
            || !hash_equals($commit->endpointId->bytes, $this->binary($run, 'endpoint_id'))
            || $commit->expectedConnectionRevision !== $this->integer($run, 'expected_connection_revision')) {
            throw new PbsContentConflict('The PBS content commit differs from its child run.');
        }
        $this->assertUsableParent($connection, $lease, new PbsContentRunStart(
            $commit->runId,
            $commit->parentRunId,
            $commit->connectionId,
            $commit->endpointId,
            $commit->binding,
            $commit->expectedConnectionRevision,
            $commit->observedAt,
        ));
        $this->assertConnectionBindingAndEndpoint($connection, new PbsContentRunStart(
            $commit->runId,
            $commit->parentRunId,
            $commit->connectionId,
            $commit->endpointId,
            $commit->binding,
            $commit->expectedConnectionRevision,
            $commit->observedAt,
        ));
    }

    /** @return array<string, mixed> */
    private function lockRun(
        Connection $connection,
        CollectorLease $lease,
        InventoryIdentifier $runId,
        InventoryIdentifier $connectionId,
    ): array {
        $run = $connection->fetchAssociative(
            'SELECT * FROM pbs_content_runs WHERE id = :run AND connection_id = :connection FOR UPDATE',
            ['run' => $runId->binary(), 'connection' => $connectionId->binary()],
        );
        if (false === $run || ($run['status'] ?? null) !== 'running' || null !== ($run['applied_at'] ?? null)
            || !is_string($run['cycle_token'] ?? null)
            || !hash_equals($lease->token->binary(), $run['cycle_token'])
            || $this->integer($run, 'collector_fencing_token') !== $lease->fencingToken) {
            throw new CollectorLeaseOwnershipLost('The PBS content run is not owned by this collector lease.');
        }
        return $run;
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

    /** @param array<string, mixed>|false $schedule
     *  @param array<string, mixed>|false $cycle
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
            throw new CollectorLeaseOwnershipLost('The PBS content write lost its collector lease.');
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
        if (is_string($value) && 1 === preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
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

    /** @param array<string, mixed> $row */
    private function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned invalid text.');
        }
        return $value;
    }

    private function count(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            return (int) $value;
        }
        throw new RuntimeException('MariaDB returned an invalid count.');
    }

    private function sameNullable(?string $expected, mixed $actual): bool
    {
        if (null === $expected) {
            return null === $actual;
        }
        return is_string($actual) && hash_equals($expected, $actual);
    }
}
