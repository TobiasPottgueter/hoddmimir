<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\Connection\InstallationIdentityValidator;
use App\Application\Monitoring\MonitoringApplyResult;
use App\Application\Monitoring\MonitoringCommit;
use App\Application\Monitoring\MonitoringConflict;
use App\Application\Monitoring\MonitoringRunKind;
use App\Application\Monitoring\MonitoringRunFailure;
use App\Application\Monitoring\MonitoringRunStart;
use App\Application\Monitoring\MonitoringRunStore;
use App\Application\Monitoring\MonitoringRunStatus;
use App\Application\Monitoring\MonitoringScopeResult;
use App\Application\Monitoring\MonitoringScopeStatus;
use App\Application\Monitoring\MonitoringScopeType;
use App\Application\Proxmox\Pbs\PbsJobObservation;
use App\Application\Proxmox\Pbs\PbsTaskObservation;
use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsUpid;
use App\Application\Proxmox\Pve\PveBackupJob;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveTaskSource;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalMonitoringRunStore implements MonitoringRunStore
{
    private const string SCHEDULE_NAME = 'inventory';

    public function __construct(
        private Connection $connection,
        private InventoryIdentifierGenerator $identifierGenerator,
    )
    {
    }

    public function begin(CollectorLease $lease, MonitoringRunStart $start): void
    {
        $this->connection->transactional(function (Connection $connection) use ($lease, $start): void {
            $this->lockAndAssertFence($connection, $lease);
            $this->lockAndAssertParent($connection, $lease, $start);
            $this->lockAndAssertConnectionAndBinding($connection, $start);
            $this->assertSelectedEndpoint($connection, $start);
            $startedAt = $this->format($start->startedAt);
            try {
                $connection->insert('proxmox_monitoring_runs', [
                    'id' => $start->runId->binary(),
                    'connection_id' => $start->connectionId->binary(),
                    'parent_sync_run_id' => $start->parentRunId->binary(),
                    'product' => $start->product->value,
                    'binding_kind' => $start->binding->kind->value,
                    'binding_value' => $start->binding->identity,
                    'binding_legacy_endpoint_id' => $start->binding->legacyEndpointId?->bytes,
                    'monitoring_kind' => $start->kind->value,
                    'expected_connection_revision' => $start->expectedConnectionRevision,
                    'endpoint_id' => $start->endpointId->bytes,
                    'cycle_token' => $lease->token->binary(),
                    'collector_fencing_token' => $lease->fencingToken,
                    'status' => 'running',
                    'started_at' => $startedAt,
                    'heartbeat_at' => $startedAt,
                ]);
            } catch (\Throwable $failure) {
                throw new MonitoringConflict('The monitoring child run could not be started exactly once.', 0, $failure);
            }
            $this->assertFenceBeforeCommit($connection, $lease);
        });
    }

    public function fail(CollectorLease $lease, MonitoringRunFailure $failure): void
    {
        $this->connection->transactional(function (Connection $connection) use ($lease, $failure): void {
            $this->lockAndAssertFence($connection, $lease);
            $run = $this->lockAndAssertRun($connection, $lease, $failure->runId, $failure->connectionId);
            $this->lockAndAssertParentFromRun($connection, $lease, $run);
            $drift = $this->connectionOrBindingDrift($connection, $run);
            $finishedAt = $this->format($failure->finishedAt);
            $updated = $connection->update('proxmox_monitoring_runs', [
                'status' => 'failed',
                'heartbeat_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'error_code' => $drift ?? $failure->errorCode,
            ], [
                'id' => $failure->runId->binary(),
                'status' => 'running',
                'applied_at' => null,
            ]);
            if (1 !== $updated) {
                throw new MonitoringConflict('The monitoring child run could not be failed exactly once.');
            }
            $this->assertFenceBeforeCommit($connection, $lease);
        });
    }

    public function apply(CollectorLease $lease, MonitoringCommit $commit): MonitoringApplyResult
    {
        if (MonitoringRunStatus::Failed === $commit->status()) {
            throw new MonitoringConflict('A monitoring commit without usable observations must be failed instead.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($lease, $commit): MonitoringApplyResult {
            $this->lockAndAssertFence($connection, $lease);
            $run = $this->lockAndAssertRun($connection, $lease, $commit->runId, $commit->connectionId);
            $this->lockAndAssertParentFromRun($connection, $lease, $run);
            if (null !== $this->connectionOrBindingDrift($connection, $run)) {
                throw new MonitoringConflict('The monitoring connection or binding changed before apply.');
            }
            $this->assertCommitMatchesRun($commit, $run);

            $created = 0;
            $updated = 0;
            $conflicts = 0;
            if (MonitoringRunKind::ExternalJobs === $commit->kind) {
                if ('pve' === $commit->product->value) {
                    foreach ($commit->pveJobs as $job) {
                        $this->upsertPveJob($connection, $commit, $job) ? ++$created : ++$updated;
                    }
                } else {
                    $serverId = $this->pbsServerId($connection, $commit->connectionId);
                    foreach ($commit->pbsJobs as $job) {
                        $this->upsertPbsJob($connection, $commit, $serverId, $job) ? ++$created : ++$updated;
                    }
                }
            } elseif ('pve' === $commit->product->value) {
                foreach ($commit->pveTasks as $task) {
                    $outcome = $this->upsertPveTask($connection, $commit, $task);
                    $created += $outcome['created'];
                    $updated += $outcome['updated'];
                    $conflicts += $outcome['conflicts'];
                }
            } else {
                $serverId = $this->pbsServerId($connection, $commit->connectionId);
                foreach ($commit->pbsTasks as $task) {
                    $outcome = $this->upsertPbsTask($connection, $commit, $serverId, $task);
                    $created += $outcome['created'];
                    $updated += $outcome['updated'];
                    $conflicts += $outcome['conflicts'];
                }
            }

            foreach ($commit->pbsInspections as $inspection) {
                $connection->update('pbs_observed_tasks', [
                    'inspection_json' => json_encode([
                        'status' => $inspection->status, 'exitStatus' => $inspection->exitStatus,
                        'endTime' => $inspection->endTime, 'lines' => $inspection->lines,
                        'truncated' => $inspection->truncated,
                        'statusFailure' => $inspection->statusFailure?->value,
                        'logFailure' => $inspection->logFailure?->value,
                    ], JSON_THROW_ON_ERROR),
                    'inspected_at' => $this->format($commit->observedAt),
                ], ['connection_id' => $commit->connectionId->binary(), 'upid_hash' => hash('sha256', $inspection->upid->value, true)]);
            }

            foreach ($commit->scopes as $scope) {
                $this->insertScope($connection, $commit, $scope);
            }
            $this->advanceCompleteCursors($connection, $commit);
            $status = $conflicts > 0 ? MonitoringRunStatus::Partial : $commit->status();
            $finishedAt = $this->format($commit->observedAt);
            $changed = $connection->update('proxmox_monitoring_runs', [
                'status' => $status->value,
                'heartbeat_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'applied_at' => $finishedAt,
                'scopes_seen' => count($commit->scopes),
                'pages_read' => $commit->pagesRead(),
                'rows_read' => $commit->rowsRead(),
                'items_seen' => $commit->itemsSeen(),
                'objects_created' => $created,
                'objects_updated' => $updated,
                'conflicts_seen' => $conflicts,
            ], ['id' => $commit->runId->binary(), 'status' => 'running', 'applied_at' => null]);
            if (1 !== $changed) {
                throw new MonitoringConflict('The monitoring child run could not be applied exactly once.');
            }
            $this->assertFenceBeforeCommit($connection, $lease);

            return new MonitoringApplyResult($status, $created, $updated, $conflicts);
        });
    }

    public function finishFailedCommit(
        CollectorLease $lease,
        MonitoringCommit $commit,
        string $errorCode,
    ): void {
        if (MonitoringRunStatus::Failed !== $commit->status()
            || '' === $errorCode || strlen($errorCode) > 64
            || 1 !== preg_match('/^[a-z0-9_]+$/D', $errorCode)) {
            throw new MonitoringConflict('The failed monitoring commit contract is invalid.');
        }

        $this->connection->transactional(function (Connection $connection) use ($lease, $commit, $errorCode): void {
            $this->lockAndAssertFence($connection, $lease);
            $run = $this->lockAndAssertRun($connection, $lease, $commit->runId, $commit->connectionId);
            $this->lockAndAssertParentFromRun($connection, $lease, $run);
            $drift = $this->connectionOrBindingDrift($connection, $run);
            $this->assertCommitMatchesRun($commit, $run);
            foreach ($commit->scopes as $scope) {
                $this->insertScope($connection, $commit, $scope);
            }
            $finishedAt = $this->format($commit->observedAt);
            $changed = $connection->update('proxmox_monitoring_runs', [
                'status' => 'failed',
                'heartbeat_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'scopes_seen' => count($commit->scopes),
                'pages_read' => $commit->pagesRead(),
                'rows_read' => $commit->rowsRead(),
                'items_seen' => $commit->itemsSeen(),
                'error_code' => $drift ?? $errorCode,
            ], ['id' => $commit->runId->binary(), 'status' => 'running', 'applied_at' => null]);
            if (1 !== $changed) {
                throw new MonitoringConflict('The failed monitoring commit could not be persisted exactly once.');
            }
            $this->assertFenceBeforeCommit($connection, $lease);
        });
    }

    /** @param array<string, mixed> $run */
    private function assertCommitMatchesRun(MonitoringCommit $commit, array $run): void
    {
        if (!hash_equals($commit->parentRunId->binary(), $this->binary($run, 'parent_sync_run_id'))
            || !hash_equals($commit->endpointId->bytes, $this->binary($run, 'endpoint_id'))
            || ($run['product'] ?? null) !== $commit->product->value
            || ($run['monitoring_kind'] ?? null) !== $commit->kind->value
            || $this->integer($run, 'expected_connection_revision') !== $commit->expectedConnectionRevision
            || ($run['binding_kind'] ?? null) !== $commit->binding->kind->value
            || !hash_equals($commit->binding->identity, $this->text($run, 'binding_value'))
            || !$this->sameNullableBinary(
                $commit->binding->legacyEndpointId?->bytes,
                $run['binding_legacy_endpoint_id'] ?? null,
            )) {
            throw new MonitoringConflict('The monitoring commit differs from its child run.');
        }
    }

    private function upsertPveJob(Connection $connection, MonitoringCommit $commit, PveBackupJob $job): bool
    {
        $row = $connection->fetchAssociative(
            'SELECT id FROM pve_external_backup_jobs WHERE connection_id = :connection AND external_job_id = :job FOR UPDATE',
            ['connection' => $commit->connectionId->binary(), 'job' => $job->id],
        );
        $prune = null === $job->pruneBackups ? null : $this->json($job->pruneBackups->signature());
        $values = [
            'raw_schedule' => $job->rawSchedule,
            'enabled' => null === $job->enabled ? null : (int) $job->enabled,
            'repeat_missed' => null === $job->repeatMissed ? null : (int) $job->repeatMissed,
            'comment' => $job->comment,
            'next_run_at' => $this->epoch($job->nextRun),
            'node_selector' => $job->node,
            'storage_name' => $job->storage,
            'guest_ids' => $job->guestIds,
            'all_guests' => null === $job->allGuests ? null : (int) $job->allGuests,
            'backup_mode' => $job->mode,
            'compression' => $job->compression,
            'legacy_max_files' => $job->legacyMaxFiles,
            'prune_json' => $prune,
            'config_hash' => hash('sha256', $this->json([
                'id' => $job->id,
                'schedule' => $job->rawSchedule,
                'enabled' => $job->enabled,
                'repeatMissed' => $job->repeatMissed,
                'comment' => $job->comment,
                'node' => $job->node,
                'storage' => $job->storage,
                'guestIds' => $job->guestIds,
                'allGuests' => $job->allGuests,
                'mode' => $job->mode,
                'compression' => $job->compression,
                'legacyMaxFiles' => $job->legacyMaxFiles,
                'prune' => $job->pruneBackups?->signature(),
            ]), true),
            'last_seen_run_id' => $commit->runId->binary(),
            'last_seen_at' => $this->format($commit->observedAt),
        ];
        if (false === $row) {
            $connection->insert('pve_external_backup_jobs', [
                'id' => $this->identifierGenerator->generate()->binary(),
                'connection_id' => $commit->connectionId->binary(),
                'external_job_id' => $job->id,
                'first_seen_run_id' => $commit->runId->binary(),
                'first_seen_at' => $this->format($commit->observedAt),
                ...$values,
            ]);
            return true;
        }
        $connection->update('pve_external_backup_jobs', $values, ['id' => $this->binary($row, 'id')]);
        return false;
    }

    private function upsertPbsJob(
        Connection $connection,
        MonitoringCommit $commit,
        string $serverId,
        PbsJobObservation $job,
    ): bool {
        $row = $connection->fetchAssociative(
            <<<'SQL'
                SELECT * FROM pbs_external_jobs
                WHERE server_id = :server AND job_kind = :kind AND external_job_id = :job
                FOR UPDATE
                SQL,
            ['server' => $serverId, 'kind' => $job->kind->value, 'job' => $job->id->value],
        );
        $details = [
            'kind' => $job->kind->value,
            'id' => $job->id->value,
            'localStore' => $job->localStore->value,
            'localNamespace' => $job->localNamespace?->value,
            'schedule' => $job->schedule,
            'disabled' => $job->disabled,
            'syncDirection' => $job->syncDirection?->value,
            'remote' => $job->remote?->value,
            'remoteStore' => $job->remoteStore?->value,
            'remoteNamespace' => $job->remoteNamespace?->value,
        ];
        $detailsJson = $this->json($details);
        $values = [
            'enabled' => (int) !$job->disabled,
            'raw_schedule' => $job->schedule,
            'target_store' => $job->localStore->value,
            'target_namespace' => $job->localNamespace?->value,
            'remote_name' => $job->remote?->value,
            'remote_store' => $job->remoteStore?->value,
            'sync_direction' => $job->syncDirection?->value,
            'last_run_upid' => $job->lastRunUpid?->value,
            'last_run_state' => $job->lastRunOutcome?->value,
            'last_run_end_at' => $this->epoch($job->lastRunEndTime),
            'next_run_at' => $this->epoch($job->nextRunTime),
            'details_json' => $detailsJson,
            'config_hash' => hash('sha256', $detailsJson, true),
            'last_seen_run_id' => $commit->runId->binary(),
            'last_seen_at' => $this->format($commit->observedAt),
        ];
        if (false === $row) {
            $connection->insert('pbs_external_jobs', [
                'id' => $this->identifierGenerator->generate()->binary(),
                'connection_id' => $commit->connectionId->binary(),
                'server_id' => $serverId,
                'job_kind' => $job->kind->value,
                'external_job_id' => $job->id->value,
                'first_seen_run_id' => $commit->runId->binary(),
                'first_seen_at' => $this->format($commit->observedAt),
                ...$values,
            ]);
            return true;
        }
        $lastRun = $this->monotonePbsJobLastRun($row, $job);
        $values['last_run_upid'] = $lastRun['upid'];
        $values['last_run_state'] = $lastRun['state'];
        $values['last_run_end_at'] = $lastRun['ended_at'];
        $connection->update('pbs_external_jobs', $values, ['id' => $this->binary($row, 'id')]);
        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{upid: mixed, state: mixed, ended_at: mixed}
     */
    private function monotonePbsJobLastRun(array $row, PbsJobObservation $job): array
    {
        $currentUpid = $row['last_run_upid'] ?? null;
        $currentState = $row['last_run_state'] ?? null;
        $currentEnd = $row['last_run_end_at'] ?? null;
        $incomingUpid = $job->lastRunUpid?->value;
        $incomingState = $job->lastRunOutcome?->value;
        $incomingEnd = $this->epoch($job->lastRunEndTime);
        if (!is_string($incomingUpid)) {
            return ['upid' => $currentUpid, 'state' => $currentState, 'ended_at' => $currentEnd];
        }
        if (!is_string($currentUpid)) {
            return ['upid' => $incomingUpid, 'state' => $incomingState, 'ended_at' => $incomingEnd];
        }

        $currentUpidValue = new PbsUpid($currentUpid);
        $incomingUpidValue = $job->lastRunUpid;
        if (hash_equals($currentUpid, $incomingUpid)) {
            return [
                'upid' => $currentUpid,
                'state' => $currentState ?? $incomingState,
                'ended_at' => $currentEnd ?? $incomingEnd,
            ];
        }
        $currentRank = [$currentUpidValue->startTime, $currentUpid];
        $incomingRank = [$incomingUpidValue->startTime, $incomingUpid];
        if ($incomingRank > $currentRank) {
            return ['upid' => $incomingUpid, 'state' => $incomingState, 'ended_at' => $incomingEnd];
        }
        if ($incomingRank < $currentRank) {
            return ['upid' => $currentUpid, 'state' => $currentState, 'ended_at' => $currentEnd];
        }

        return ['upid' => $currentUpid, 'state' => $currentState, 'ended_at' => $currentEnd];
    }

    /** @return array{created: int, updated: int, conflicts: int} */
    private function upsertPveTask(Connection $connection, MonitoringCommit $commit, PveBackupTask $task): array
    {
        $hash = hash('sha256', $task->upid->raw, true);
        $row = $connection->fetchAssociative(
            'SELECT * FROM pve_observed_backup_tasks WHERE connection_id = :connection AND upid_hash = :hash FOR UPDATE',
            ['connection' => $commit->connectionId->binary(), 'hash' => $hash],
        );
        if (false !== $row && !hash_equals($task->upid->raw, $this->text($row, 'upid_raw'))) {
            throw new MonitoringConflict('A PVE UPID hash collision was detected.');
        }
        $parts = explode(':', $task->upid->raw);
        $incomingLifecycle = $this->pveLifecycle($task);
        $incomingFinishedAt = $this->epoch($task->endTime);
        if (false === $row) {
            $connection->insert('pve_observed_backup_tasks', [
                'id' => $this->identifierGenerator->generate()->binary(),
                'connection_id' => $commit->connectionId->binary(),
                'upid_hash' => $hash,
                'upid_raw' => $task->upid->raw,
                'node_name' => $task->upid->node,
                'pid_hex' => strtolower($parts[2]),
                'pstart_hex' => strtolower($parts[3]),
                'starttime_hex' => strtolower($parts[4]),
                'task_id' => $task->upid->id,
                'auth_id' => $task->upid->user,
                'seen_active' => (int) $task->seenActive,
                'seen_archive' => (int) $task->seenArchive,
                'lifecycle' => $incomingLifecycle,
                'remote_status' => $task->listStatus,
                'started_at' => $this->epoch($task->upid->startTime),
                'finished_at' => $incomingFinishedAt,
                'first_seen_run_id' => $commit->runId->binary(),
                'last_seen_run_id' => $commit->runId->binary(),
                'first_seen_at' => $this->format($commit->observedAt),
                'last_seen_at' => $this->format($commit->observedAt),
            ]);
            return ['created' => 1, 'updated' => 0, 'conflicts' => 0];
        }
        $merge = $this->mergeTaskLifecycle($row, $incomingLifecycle, $incomingFinishedAt, $task->listStatus);
        $connection->update('pve_observed_backup_tasks', [
            'seen_active' => max($this->integer($row, 'seen_active'), (int) $task->seenActive),
            'seen_archive' => max($this->integer($row, 'seen_archive'), (int) $task->seenArchive),
            'lifecycle' => $merge['lifecycle'],
            'remote_status' => $merge['remote_status'],
            'finished_at' => $merge['finished_at'],
            'last_seen_run_id' => $commit->runId->binary(),
            'last_seen_at' => $this->format($commit->observedAt),
        ], ['id' => $this->binary($row, 'id')]);
        return ['created' => 0, 'updated' => 1, 'conflicts' => $merge['conflict'] ? 1 : 0];
    }

    /** @return array{created: int, updated: int, conflicts: int} */
    private function upsertPbsTask(
        Connection $connection,
        MonitoringCommit $commit,
        string $serverId,
        PbsTaskObservation $task,
    ): array {
        $hash = hash('sha256', $task->upid->value, true);
        $row = $connection->fetchAssociative(
            'SELECT * FROM pbs_observed_tasks WHERE connection_id = :connection AND upid_hash = :hash FOR UPDATE',
            ['connection' => $commit->connectionId->binary(), 'hash' => $hash],
        );
        if (false !== $row && !hash_equals($task->upid->value, $this->text($row, 'upid_raw'))) {
            throw new MonitoringConflict('A PBS UPID hash collision was detected.');
        }
        $lifecycle = $task->isRunning() ? 'running' : 'stopped';
        $finishedAt = $this->epoch($task->endTime);
        $remoteStatus = $task->outcome?->value;
        if (false === $row) {
            $connection->insert('pbs_observed_tasks', [
                'id' => $this->identifierGenerator->generate()->binary(),
                'connection_id' => $commit->connectionId->binary(),
                'server_id' => $serverId,
                'upid_hash' => $hash,
                'upid_raw' => $task->upid->value,
                'upid_node_name' => $task->upid->node,
                'reported_node_name' => $task->reportedNode,
                'pid_hex' => $task->upid->pidHex,
                'pstart_hex' => $task->upid->processStartHex,
                'task_id_hex' => $task->upid->taskIdHex,
                'starttime_hex' => $task->upid->startTimeHex,
                'worker_type' => $task->upid->workerType,
                'worker_id' => $task->upid->workerId,
                'auth_id' => $task->upid->authId,
                'seen_running' => (int) $task->seenRunning,
                'seen_history' => (int) $task->seenHistory,
                'lifecycle' => $lifecycle,
                'remote_status' => $remoteStatus,
                'started_at' => $this->epoch($task->upid->startTime),
                'finished_at' => $finishedAt,
                'first_seen_run_id' => $commit->runId->binary(),
                'last_seen_run_id' => $commit->runId->binary(),
                'first_seen_at' => $this->format($commit->observedAt),
                'last_seen_at' => $this->format($commit->observedAt),
            ]);
            return ['created' => 1, 'updated' => 0, 'conflicts' => 0];
        }
        $merge = $this->mergeTaskLifecycle($row, $lifecycle, $finishedAt, $remoteStatus);
        $connection->update('pbs_observed_tasks', [
            'reported_node_name' => $this->preferredPbsReportedNode(
                $row['reported_node_name'] ?? null,
                $task->reportedNode,
            ),
            'seen_running' => max($this->integer($row, 'seen_running'), (int) $task->seenRunning),
            'seen_history' => max($this->integer($row, 'seen_history'), (int) $task->seenHistory),
            'lifecycle' => $merge['lifecycle'],
            'remote_status' => $merge['remote_status'],
            'finished_at' => $merge['finished_at'],
            'last_seen_run_id' => $commit->runId->binary(),
            'last_seen_at' => $this->format($commit->observedAt),
        ], ['id' => $this->binary($row, 'id')]);
        return ['created' => 0, 'updated' => 1, 'conflicts' => $merge['conflict'] ? 1 : 0];
    }

    private function preferredPbsReportedNode(mixed $current, ?string $incoming): ?string
    {
        $nodes = array_values(array_unique(array_filter(
            [is_string($current) ? $current : null, $incoming],
            static fn (?string $node): bool => null !== $node,
        )));
        usort($nodes, static fn (string $left, string $right): int => match (true) {
            'localhost' === $left && 'localhost' !== $right => 1,
            'localhost' !== $left && 'localhost' === $right => -1,
            default => strcmp($left, $right),
        });
        return $nodes[0] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{lifecycle: string, finished_at: mixed, remote_status: mixed, conflict: bool}
     */
    private function mergeTaskLifecycle(
        array $row,
        string $incomingLifecycle,
        ?string $incomingFinishedAt,
        ?string $incomingStatus,
    ): array {
        $currentLifecycle = $this->text($row, 'lifecycle');
        $currentFinishedAt = $row['finished_at'] ?? null;
        $currentStatus = $row['remote_status'] ?? null;
        if ('stopped' === $currentLifecycle && 'stopped' !== $incomingLifecycle) {
            return [
                'lifecycle' => $currentLifecycle,
                'finished_at' => $currentFinishedAt,
                'remote_status' => $currentStatus,
                'conflict' => false,
            ];
        }
        if ('unknown' === $incomingLifecycle) {
            return [
                'lifecycle' => $currentLifecycle,
                'finished_at' => $currentFinishedAt,
                'remote_status' => $currentStatus,
                'conflict' => false,
            ];
        }
        if ('stopped' === $currentLifecycle
            && (null !== $currentFinishedAt && null !== $incomingFinishedAt && $currentFinishedAt !== $incomingFinishedAt
                || null !== $currentStatus && null !== $incomingStatus && $currentStatus !== $incomingStatus)) {
            return [
                'lifecycle' => $currentLifecycle,
                'finished_at' => $currentFinishedAt,
                'remote_status' => $currentStatus,
                'conflict' => true,
            ];
        }
        return [
            'lifecycle' => $incomingLifecycle,
            'finished_at' => $incomingFinishedAt ?? $currentFinishedAt,
            'remote_status' => $incomingStatus ?? $currentStatus,
            'conflict' => false,
        ];
    }

    private function pveLifecycle(PveBackupTask $task): string
    {
        if (null !== $task->endTime || null !== $task->listStatus && 'RUNNING' !== $task->listStatus) {
            return 'stopped';
        }
        if (PveTaskSource::Active === $task->source || 'RUNNING' === $task->listStatus) {
            return 'running';
        }
        return 'unknown';
    }

    private function insertScope(Connection $connection, MonitoringCommit $commit, MonitoringScopeResult $scope): void
    {
        $connection->insert('proxmox_monitoring_scope_results', [
            'id' => $this->identifierGenerator->generate()->binary(),
            'connection_id' => $commit->connectionId->binary(),
            'monitoring_run_id' => $commit->runId->binary(),
            'scope_type' => $scope->scope->value,
            'scope_key' => $scope->key,
            'source_kind' => $scope->source->value,
            'filter_value' => $scope->filter ?? '@none',
            'status' => $scope->status->value,
            'window_since' => null === $scope->windowSince ? null : $this->format($scope->windowSince),
            'window_until' => null === $scope->windowUntil ? null : $this->format($scope->windowUntil),
            'pages_read' => $scope->pagesRead,
            'rows_read' => $scope->rowsRead,
            'items_seen' => $scope->itemsSeen,
            'truncated' => (int) $scope->truncated,
            'history_gap' => (int) $scope->historyGap,
            'error_code' => $scope->errorCode,
            'observed_at' => $this->format($scope->observedAt),
        ]);
    }

    private function advanceCompleteCursors(Connection $connection, MonitoringCommit $commit): void
    {
        if ('pve' === $commit->product->value) {
            foreach ($commit->scopes as $scope) {
                if (MonitoringScopeType::PveTasksArchive === $scope->scope && $this->scopeAdvancesCursor($scope)) {
                    $this->upsertCursor($connection, $commit, 'pve_tasks_archive', $scope->key, $scope);
                }
            }
            return;
        }

        /** @var array<string, list<MonitoringScopeResult>> $groups */
        $groups = [];
        foreach ($commit->scopes as $scope) {
            if (MonitoringScopeType::PbsTasksWindow === $scope->scope) {
                $groups[$scope->key][] = $scope;
            }
        }
        $requiredFilters = array_map(
            static fn (PbsTaskFilterFamily $family): string => $family->value,
            PbsTaskFilterFamily::cases(),
        );
        sort($requiredFilters, SORT_STRING);
        foreach ($groups as $scopeKey => $scopes) {
            $filters = array_map(static fn (MonitoringScopeResult $scope): string => $scope->filter ?? '', $scopes);
            sort($filters, SORT_STRING);
            if ($filters !== $requiredFilters) {
                continue;
            }
            $first = $scopes[0];
            $complete = true;
            foreach ($scopes as $scope) {
                if (!$this->scopeAdvancesCursor($scope)
                    || $scope->windowSince != $first->windowSince
                    || $scope->windowUntil != $first->windowUntil) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                $this->upsertCursor($connection, $commit, 'pbs_tasks_window', $scopeKey, $first);
            }
        }
    }

    private function scopeAdvancesCursor(MonitoringScopeResult $scope): bool
    {
        return MonitoringScopeStatus::Complete === $scope->status
            && !$scope->truncated && !$scope->historyGap && null !== $scope->windowUntil;
    }

    private function upsertCursor(
        Connection $connection,
        MonitoringCommit $commit,
        string $cursorKind,
        string $scopeKey,
        MonitoringScopeResult $scope,
    ): void {
        if (null === $scope->windowUntil) {
            throw new \LogicException('A complete monitoring cursor scope requires its window end.');
        }
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO proxmox_monitoring_cursors (
                    connection_id, product, cursor_kind, scope_key,
                    completed_until, last_complete_run_id, updated_at
                ) VALUES (
                    :connection, :product, :kind, :scope,
                    :completed, :run, :updated
                )
                ON DUPLICATE KEY UPDATE
                    completed_until = IF(completed_until < VALUES(completed_until), VALUES(completed_until), completed_until),
                    last_complete_run_id = IF(completed_until <= VALUES(completed_until), VALUES(last_complete_run_id), last_complete_run_id),
                    updated_at = IF(completed_until <= VALUES(completed_until), VALUES(updated_at), updated_at)
                SQL,
            [
                'connection' => $commit->connectionId->binary(),
                'product' => $commit->product->value,
                'kind' => $cursorKind,
                'scope' => $scopeKey,
                'completed' => $this->format($scope->windowUntil),
                'run' => $commit->runId->binary(),
                'updated' => $this->format($commit->observedAt),
            ],
        );
    }

    private function pbsServerId(Connection $connection, InventoryIdentifier $connectionId): string
    {
        $server = $connection->fetchOne(
            'SELECT id FROM pbs_servers WHERE connection_id = :connection FOR UPDATE',
            ['connection' => $connectionId->binary()],
        );
        if (!is_string($server) || 16 !== strlen($server)) {
            throw new MonitoringConflict('The bound PBS server projection is missing.');
        }
        return $server;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function epoch(?int $seconds): ?string
    {
        if (null === $seconds) {
            return null;
        }
        return (new DateTimeImmutable('@'.$seconds))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    /** @return array<string, mixed> */
    private function lockAndAssertParent(
        Connection $connection,
        CollectorLease $lease,
        MonitoringRunStart $start,
    ): array {
        $parent = $connection->fetchAssociative(
            'SELECT * FROM inventory_sync_runs WHERE id = :run AND connection_id = :connection FOR UPDATE',
            ['run' => $start->parentRunId->binary(), 'connection' => $start->connectionId->binary()],
        );
        if (false === $parent
            || !in_array($parent['status'] ?? null, ['succeeded', 'partial'], true)
            || !is_string($parent['applied_at'] ?? null)
            || !is_string($parent['endpoint_id'] ?? null)
            || !hash_equals($start->endpointId->bytes, $parent['endpoint_id'])
            || $this->integer($parent, 'expected_connection_revision') !== $start->expectedConnectionRevision
            || !is_string($parent['cycle_token'] ?? null)
            || !hash_equals($lease->token->binary(), $parent['cycle_token'])
            || $this->integer($parent, 'collector_fencing_token') !== $lease->fencingToken) {
            throw new MonitoringConflict('The monitoring parent run is not usable by this child.');
        }

        return $parent;
    }

    private function lockAndAssertConnectionAndBinding(Connection $connection, MonitoringRunStart $start): void
    {
        $row = $connection->fetchAssociative(
            'SELECT product, enabled, revision FROM proxmox_connections WHERE id = :id FOR UPDATE',
            ['id' => $start->connectionId->binary()],
        );
        if (false === $row
            || ($row['product'] ?? null) !== $start->product->value
            || 1 !== $this->integer($row, 'enabled')
            || $this->integer($row, 'revision') !== $start->expectedConnectionRevision) {
            throw new MonitoringConflict('The monitoring connection changed before the child run started.');
        }
        $binding = $connection->fetchAssociative(
            'SELECT product, identity_kind, identity_value, legacy_endpoint_id FROM proxmox_installation_bindings WHERE connection_id = :id FOR UPDATE',
            ['id' => $start->connectionId->binary()],
        );
        if (false === $binding
            || ($binding['product'] ?? null) !== $start->product->value
            || ($binding['identity_kind'] ?? null) !== $start->binding->kind->value
            || !is_string($binding['identity_value'] ?? null)
            || !hash_equals($start->binding->identity, $binding['identity_value'])
            || !$this->sameNullableBinary(
                $start->binding->legacyEndpointId?->bytes,
                $binding['legacy_endpoint_id'] ?? null,
            )) {
            throw new MonitoringConflict('A verified installation binding is required for monitoring persistence.');
        }
    }

    /** @param array<string, mixed> $run */
    private function connectionOrBindingDrift(Connection $connection, array $run): ?string
    {
        $connectionId = $this->binary($run, 'connection_id');
        $product = $this->text($run, 'product');
        $revision = $this->integer($run, 'expected_connection_revision');
        $bindingKind = $this->text($run, 'binding_kind');
        $bindingValue = $this->text($run, 'binding_value');
        $bindingLegacyEndpoint = $run['binding_legacy_endpoint_id'] ?? null;
        $endpointId = $this->binary($run, 'endpoint_id');
        $row = $connection->fetchAssociative(
            'SELECT product, enabled, revision FROM proxmox_connections WHERE id = :id FOR UPDATE',
            ['id' => $connectionId],
        );
        $binding = $connection->fetchAssociative(
            'SELECT product, identity_kind, identity_value, legacy_endpoint_id FROM proxmox_installation_bindings WHERE connection_id = :id FOR UPDATE',
            ['id' => $connectionId],
        );
        if (false === $row || ($row['product'] ?? null) !== $product
            || 1 !== $this->integer($row, 'enabled') || $this->integer($row, 'revision') !== $revision) {
            return 'connection_changed';
        }
        if (!$this->validPersistedBinding($product, $bindingKind, $bindingValue, $bindingLegacyEndpoint, $endpointId)
            || false === $binding || ($binding['product'] ?? null) !== $product
            || ($binding['identity_kind'] ?? null) !== $bindingKind
            || !is_string($binding['identity_value'] ?? null)
            || !hash_equals($bindingValue, $binding['identity_value'])
            || !$this->sameNullableBinary($bindingLegacyEndpoint, $binding['legacy_endpoint_id'] ?? null)) {
            return 'binding_changed';
        }

        return null;
    }

    /** @param array<string, mixed> $run */
    private function lockAndAssertParentFromRun(Connection $connection, CollectorLease $lease, array $run): void
    {
        $parent = $connection->fetchAssociative(
            'SELECT * FROM inventory_sync_runs WHERE id = :run AND connection_id = :connection FOR UPDATE',
            [
                'run' => $this->binary($run, 'parent_sync_run_id'),
                'connection' => $this->binary($run, 'connection_id'),
            ],
        );
        if (false === $parent
            || !in_array($parent['status'] ?? null, ['succeeded', 'partial'], true)
            || !is_string($parent['applied_at'] ?? null)
            || !is_string($parent['endpoint_id'] ?? null)
            || !hash_equals($this->binary($run, 'endpoint_id'), $parent['endpoint_id'])
            || $this->integer($parent, 'expected_connection_revision')
                !== $this->integer($run, 'expected_connection_revision')
            || !is_string($parent['cycle_token'] ?? null)
            || !hash_equals($lease->token->binary(), $parent['cycle_token'])
            || $this->integer($parent, 'collector_fencing_token') !== $lease->fencingToken) {
            throw new MonitoringConflict('The monitoring parent changed while its child run was active.');
        }
    }

    private function assertSelectedEndpoint(Connection $connection, MonitoringRunStart $start): void
    {
        $selected = $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM inventory_sync_endpoint_attempts
                WHERE sync_run_id = :run AND endpoint_id = :endpoint AND outcome = 'selected'
                SQL,
            ['run' => $start->parentRunId->binary(), 'endpoint' => $start->endpointId->bytes],
        );
        if (1 !== $this->count($selected)) {
            throw new MonitoringConflict('The monitoring endpoint was not selected by its parent run.');
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
            'SELECT * FROM proxmox_monitoring_runs WHERE id = :run AND connection_id = :connection FOR UPDATE',
            ['run' => $runId->binary(), 'connection' => $connectionId->binary()],
        );
        if (false === $run
            || ($run['status'] ?? null) !== 'running'
            || null !== ($run['applied_at'] ?? null)
            || !is_string($run['cycle_token'] ?? null)
            || !hash_equals($lease->token->binary(), $run['cycle_token'])
            || $this->integer($run, 'collector_fencing_token') !== $lease->fencingToken) {
            throw new CollectorLeaseOwnershipLost('The monitoring child run is not owned by this collector lease.');
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
            throw new CollectorLeaseOwnershipLost('The monitoring write lost its collector lease.');
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
        if (!is_string($value) || '' === $value) {
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

    private function sameNullableBinary(mixed $expected, mixed $actual): bool
    {
        if (null === $expected || null === $actual) {
            return null === $expected && null === $actual;
        }

        return is_string($expected) && 16 === strlen($expected)
            && is_string($actual) && 16 === strlen($actual)
            && hash_equals($expected, $actual);
    }

    private function validPersistedBinding(
        string $product,
        string $kind,
        string $value,
        mixed $legacyEndpoint,
        string $selectedEndpoint,
    ): bool {
        if (!InstallationIdentityValidator::isValid($value)) {
            return false;
        }
        if ('pve' === $product) {
            return ('pve_cluster' === $kind || 'pve_standalone' === $kind) && null === $legacyEndpoint;
        }
        if ('pbs' !== $product) {
            return false;
        }
        if ('pbs_instance' === $kind) {
            return null === $legacyEndpoint
                && 32 === strlen($value)
                && 32 === strspn($value, '0123456789abcdef');
        }

        return 'pbs_legacy_node' === $kind
            && is_string($legacyEndpoint)
            && 16 === strlen($legacyEndpoint)
            && hash_equals($selectedEndpoint, $legacyEndpoint);
    }
}
