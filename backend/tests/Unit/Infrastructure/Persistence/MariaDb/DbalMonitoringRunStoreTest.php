<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Monitoring\MonitoringCommit;
use App\Application\Monitoring\MonitoringConflict;
use App\Application\Monitoring\MonitoringRunFailure;
use App\Application\Monitoring\MonitoringRunKind;
use App\Application\Monitoring\MonitoringRunStart;
use App\Application\Monitoring\MonitoringScopeResult;
use App\Application\Monitoring\MonitoringScopeStatus;
use App\Application\Monitoring\MonitoringScopeType;
use App\Application\Monitoring\MonitoringSourceKind;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsJobId;
use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsJobObservation;
use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsTaskObservation;
use App\Application\Proxmox\Pbs\PbsTaskOutcome;
use App\Application\Proxmox\Pbs\PbsUpid as PbsUpid;
use App\Application\Proxmox\Pve\PveBackupJob;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveUpid;
use App\Infrastructure\Persistence\MariaDb\DbalMonitoringRunStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DbalMonitoringRunStoreTest extends TestCase
{
    private const string NOW = '2026-07-12 09:00:00.000000';

    public function testBeginFailAndFailedCommitAreFencedAndRecorded(): void
    {
        $begin = new MonitoringStoreRecording();
        $this->store($this->database(recording: $begin))->begin($this->lease(), $this->start());
        self::assertSame([
            'id' => $this->id('run')->binary(),
            'connection_id' => $this->id('connection')->binary(),
            'parent_sync_run_id' => $this->id('parent')->binary(),
            'product' => 'pve',
            'binding_kind' => 'pve_standalone',
            'binding_value' => 'pve-a',
            'binding_legacy_endpoint_id' => null,
            'monitoring_kind' => 'observed_tasks',
            'expected_connection_revision' => 1,
            'endpoint_id' => $this->endpoint()->bytes,
            'cycle_token' => $this->id('cycle')->binary(),
            'collector_fencing_token' => 7,
            'status' => 'running',
            'started_at' => self::NOW,
            'heartbeat_at' => self::NOW,
        ], $this->insertValues($begin, 'proxmox_monitoring_runs'));

        $failed = new MonitoringStoreRecording();
        $this->store($this->database(recording: $failed))->fail(
            $this->lease(),
            new MonitoringRunFailure($this->id('run'), $this->id('connection'), 'read_failed', $this->now()),
        );
        self::assertSame([
            'status' => 'failed',
            'heartbeat_at' => self::NOW,
            'finished_at' => self::NOW,
            'error_code' => 'read_failed',
        ], $this->updateValues($failed, 'proxmox_monitoring_runs'));
        self::assertSame([
            'id' => $this->id('run')->binary(),
            'status' => 'running',
            'applied_at' => null,
        ], $this->updateCriteria($failed, 'proxmox_monitoring_runs'));

        $diagnostic = new MonitoringStoreRecording();
        $this->store($this->database(recording: $diagnostic))->finishFailedCommit(
            $this->lease(),
            $this->pveCommit(MonitoringRunKind::ObservedTasks, [$this->pveArchiveScope(MonitoringScopeStatus::Failed)]),
            'all_scopes_failed',
        );
        self::assertSame($this->expectedScopeInsert(
            0,
            MonitoringScopeType::PveTasksArchive,
            '@installation',
            MonitoringSourceKind::Archive,
            'vzdump',
            MonitoringScopeStatus::Failed,
            true,
            'read_failed',
        ), $this->insertValues($diagnostic, 'proxmox_monitoring_scope_results'));
        self::assertSame([
            'status' => 'failed',
            'heartbeat_at' => self::NOW,
            'finished_at' => self::NOW,
            'scopes_seen' => 1,
            'pages_read' => 1,
            'rows_read' => 1,
            'items_seen' => 0,
            'error_code' => 'all_scopes_failed',
        ], $this->updateValues($diagnostic, 'proxmox_monitoring_runs'));
        self::assertSame([
            'id' => $this->id('run')->binary(),
            'status' => 'running',
            'applied_at' => null,
        ], $this->updateCriteria($diagnostic, 'proxmox_monitoring_runs'));
    }

    public function testPveJobsAndTasksCoverCreateUpdateMonotoneConflictAndCursorPaths(): void
    {
        $createdJobs = new MonitoringStoreRecording();
        $jobResult = $this->store($this->database(['kind' => 'external_jobs'], $createdJobs))->apply(
            $this->lease(),
            $this->pveCommit(MonitoringRunKind::ExternalJobs, [$this->pveJobScope()], jobs: [$this->pveJob()]),
        );
        self::assertSame([1, 0, 0], [$jobResult->created, $jobResult->updated, $jobResult->conflicts]);
        $job = $this->pveJob();
        $jobConfiguration = [
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
        ];
        self::assertSame([
            'id' => $this->generatedId(0),
            'connection_id' => $this->id('connection')->binary(),
            'external_job_id' => 'job-a',
            'first_seen_run_id' => $this->id('run')->binary(),
            'first_seen_at' => self::NOW,
            'raw_schedule' => 'daily',
            'enabled' => 1,
            'repeat_missed' => 1,
            'comment' => 'daily backup',
            'next_run_at' => $this->epoch(1_759_061_000),
            'node_selector' => 'pve-a',
            'storage_name' => 'backup',
            'guest_ids' => '101',
            'all_guests' => 0,
            'backup_mode' => 'snapshot',
            'compression' => 'zstd',
            'legacy_max_files' => null,
            'prune_json' => null,
            'config_hash' => hash('sha256', $this->json($jobConfiguration), true),
            'last_seen_run_id' => $this->id('run')->binary(),
            'last_seen_at' => self::NOW,
        ], $this->insertValues($createdJobs, 'pve_external_backup_jobs'));
        self::assertSame($this->expectedScopeInsert(
            1,
            MonitoringScopeType::PveBackupJobs,
            '@installation',
            MonitoringSourceKind::Jobs,
            null,
            MonitoringScopeStatus::Complete,
            false,
            null,
        ), $this->insertValues($createdJobs, 'proxmox_monitoring_scope_results'));
        self::assertSame([
            'status' => 'succeeded',
            'heartbeat_at' => self::NOW,
            'finished_at' => self::NOW,
            'applied_at' => self::NOW,
            'scopes_seen' => 1,
            'pages_read' => 1,
            'rows_read' => 1,
            'items_seen' => 1,
            'objects_created' => 1,
            'objects_updated' => 0,
            'conflicts_seen' => 0,
        ], $this->updateValues($createdJobs, 'proxmox_monitoring_runs'));
        self::assertSame([
            'id' => $this->id('run')->binary(),
            'status' => 'running',
            'applied_at' => null,
        ], $this->updateCriteria($createdJobs, 'proxmox_monitoring_runs'));

        $updatedJobs = new MonitoringStoreRecording();
        $this->store($this->database([
            'kind' => 'external_jobs',
            'pve_job' => ['id' => $this->id('job-row')->binary()],
        ], $updatedJobs))->apply(
            $this->lease(),
            $this->pveCommit(MonitoringRunKind::ExternalJobs, [$this->pveJobScope()], jobs: [$this->pveJob()]),
        );
        self::assertSame('pve_external_backup_jobs', $updatedJobs->updates[0][0]);
        self::assertSame(
            ['id' => $this->id('job-row')->binary()],
            $this->updateCriteria($updatedJobs, 'pve_external_backup_jobs'),
        );

        $createdTasks = new MonitoringStoreRecording();
        $taskResult = $this->store($this->database(recording: $createdTasks))->apply(
            $this->lease(),
            $this->pveCommit(
                MonitoringRunKind::ObservedTasks,
                [$this->pveArchiveScope(MonitoringScopeStatus::Complete)],
                tasks: [$this->pveTask(PveTaskSource::Archive, 1_728_053_300, 'OK')],
            ),
        );
        self::assertSame([1, 0, 0], [$taskResult->created, $taskResult->updated, $taskResult->conflicts]);
        $task = $this->pveTask(PveTaskSource::Archive, 1_728_053_300, 'OK');
        $parts = explode(':', $task->upid->raw);
        self::assertSame([
            'id' => $this->generatedId(0),
            'connection_id' => $this->id('connection')->binary(),
            'upid_hash' => hash('sha256', $task->upid->raw, true),
            'upid_raw' => $task->upid->raw,
            'node_name' => 'pve-a',
            'pid_hex' => strtolower($parts[2]),
            'pstart_hex' => strtolower($parts[3]),
            'starttime_hex' => strtolower($parts[4]),
            'task_id' => '101',
            'auth_id' => 'observer@pve',
            'seen_active' => 0,
            'seen_archive' => 1,
            'lifecycle' => 'stopped',
            'remote_status' => 'OK',
            'started_at' => $this->epoch($task->upid->startTime),
            'finished_at' => $this->epoch(1_728_053_300),
            'first_seen_run_id' => $this->id('run')->binary(),
            'last_seen_run_id' => $this->id('run')->binary(),
            'first_seen_at' => self::NOW,
            'last_seen_at' => self::NOW,
        ], $this->insertValues($createdTasks, 'pve_observed_backup_tasks'));
        self::assertSame([
            'connection' => $this->id('connection')->binary(),
            'product' => 'pve',
            'kind' => 'pve_tasks_archive',
            'scope' => '@installation',
            'completed' => self::NOW,
            'run' => $this->id('run')->binary(),
            'updated' => self::NOW,
        ], $createdTasks->statements[0][1]);

        $existingTask = $this->pveTaskRow('stopped', '2024-10-04 12:10:00.000000', 'OK');
        $updatedTasks = new MonitoringStoreRecording();
        $conflict = $this->store($this->database(['pve_task' => $existingTask], $updatedTasks))->apply(
            $this->lease(),
            $this->pveCommit(
                MonitoringRunKind::ObservedTasks,
                [$this->pveArchiveScope(MonitoringScopeStatus::Complete)],
                tasks: [$this->pveTask(PveTaskSource::Active, null, 'RUNNING')],
            ),
        );
        self::assertSame(0, $conflict->conflicts);
        self::assertSame('stopped', $updatedTasks->updates[0][1]['lifecycle']);

        $terminalConflict = new MonitoringStoreRecording();
        $result = $this->store($this->database(['pve_task' => $existingTask], $terminalConflict))->apply(
            $this->lease(),
            $this->pveCommit(
                MonitoringRunKind::ObservedTasks,
                [$this->pveArchiveScope(MonitoringScopeStatus::Complete)],
                tasks: [$this->pveTask(PveTaskSource::Archive, 1_728_053_400, 'ERROR')],
            ),
        );
        self::assertSame('partial', $result->status->value);
        self::assertSame(1, $result->conflicts);
    }

    public function testPbsJobsAndTasksCoverCreateUpdateLastRunAndCompleteCursorFamilies(): void
    {
        $binding = InstallationBinding::pbsLegacyNode('pbs-a', $this->endpoint());
        $job = $this->pbsJob();
        $createdJobs = new MonitoringStoreRecording();
        $result = $this->store($this->database([
            'product' => 'pbs', 'binding' => $binding, 'kind' => 'external_jobs',
        ], $createdJobs))->apply(
            $this->lease(),
            $this->pbsCommit(MonitoringRunKind::ExternalJobs, [$this->pbsJobScope()], jobs: [$job], binding: $binding),
        );
        self::assertSame(1, $result->created);
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
        self::assertSame([
            'id' => $this->generatedId(0),
            'connection_id' => $this->id('connection')->binary(),
            'server_id' => $this->id('pbs-server')->binary(),
            'job_kind' => 'prune',
            'external_job_id' => 'prune-a',
            'first_seen_run_id' => $this->id('run')->binary(),
            'first_seen_at' => self::NOW,
            'enabled' => 1,
            'raw_schedule' => 'daily',
            'target_store' => 'store-a',
            'target_namespace' => null,
            'remote_name' => null,
            'remote_store' => null,
            'sync_direction' => null,
            'last_run_upid' => $job->lastRunUpid?->value,
            'last_run_state' => 'ok',
            'last_run_end_at' => null,
            'next_run_at' => $this->epoch(1_759_061_000),
            'details_json' => $detailsJson,
            'config_hash' => hash('sha256', $detailsJson, true),
            'last_seen_run_id' => $this->id('run')->binary(),
            'last_seen_at' => self::NOW,
        ], $this->insertValues($createdJobs, 'pbs_external_jobs'));

        $jobRow = [
            'id' => $this->id('pbs-job-row')->binary(),
            'last_run_upid' => $job->lastRunUpid?->value,
            'last_run_state' => 'ok',
            'last_run_end_at' => null,
        ];
        $updatedJobs = new MonitoringStoreRecording();
        $this->store($this->database([
            'product' => 'pbs', 'binding' => $binding, 'kind' => 'external_jobs', 'pbs_job' => $jobRow,
        ], $updatedJobs))->apply(
            $this->lease(),
            $this->pbsCommit(MonitoringRunKind::ExternalJobs, [$this->pbsJobScope()], jobs: [$job], binding: $binding),
        );
        self::assertSame('pbs_external_jobs', $updatedJobs->updates[0][0]);

        $createdTasks = new MonitoringStoreRecording();
        $task = $this->pbsTask(null, null);
        $taskResult = $this->store($this->database(['product' => 'pbs', 'binding' => $binding], $createdTasks))->apply(
            $this->lease(),
            $this->pbsCommit(
                MonitoringRunKind::ObservedTasks,
                $this->pbsHistoryScopes(),
                tasks: [$task],
                binding: $binding,
            ),
        );
        self::assertSame(1, $taskResult->created);
        self::assertSame([
            'id' => $this->generatedId(0),
            'connection_id' => $this->id('connection')->binary(),
            'server_id' => $this->id('pbs-server')->binary(),
            'upid_hash' => hash('sha256', $task->upid->value, true),
            'upid_raw' => $task->upid->value,
            'upid_node_name' => $task->upid->node,
            'reported_node_name' => 'localhost',
            'pid_hex' => $task->upid->pidHex,
            'pstart_hex' => $task->upid->processStartHex,
            'task_id_hex' => $task->upid->taskIdHex,
            'starttime_hex' => $task->upid->startTimeHex,
            'worker_type' => $task->upid->workerType,
            'worker_id' => $task->upid->workerId,
            'auth_id' => $task->upid->authId,
            'seen_running' => 1,
            'seen_history' => 1,
            'lifecycle' => 'running',
            'remote_status' => null,
            'started_at' => $this->epoch($task->upid->startTime),
            'finished_at' => null,
            'first_seen_run_id' => $this->id('run')->binary(),
            'last_seen_run_id' => $this->id('run')->binary(),
            'first_seen_at' => self::NOW,
            'last_seen_at' => self::NOW,
        ], $this->insertValues($createdTasks, 'pbs_observed_tasks'));

        $existing = [
            'id' => $this->id('pbs-task-row')->binary(),
            'upid_raw' => $task->upid->value,
            'reported_node_name' => 'localhost',
            'seen_running' => 1,
            'seen_history' => 0,
            'lifecycle' => 'running',
            'remote_status' => null,
            'finished_at' => null,
        ];
        $updatedTasks = new MonitoringStoreRecording();
        $this->store($this->database([
            'product' => 'pbs', 'binding' => $binding, 'pbs_task' => $existing,
        ], $updatedTasks))->apply(
            $this->lease(),
            $this->pbsCommit(
                MonitoringRunKind::ObservedTasks,
                [$this->pbsRunningScope()],
                tasks: [$this->pbsTask(PbsTaskOutcome::Ok, 1_759_061_954, 'pbs-a')],
                binding: $binding,
            ),
        );
        self::assertSame('pbs-a', $updatedTasks->updates[0][1]['reported_node_name']);
        self::assertSame('stopped', $updatedTasks->updates[0][1]['lifecycle']);
    }

    public function testPveLifecycleDisjunctionOperandsAreIndependentlyRequired(): void
    {
        $scenarios = [
            [PveTaskSource::Archive, 1_728_053_300, 'RUNNING', 'stopped'],
            [PveTaskSource::Archive, null, 'ERROR', 'stopped'],
            [PveTaskSource::Active, null, null, 'running'],
            [PveTaskSource::Archive, null, 'RUNNING', 'running'],
        ];

        foreach ($scenarios as [$source, $endTime, $remoteStatus, $expectedLifecycle]) {
            $recording = new MonitoringStoreRecording();
            $this->store($this->database(recording: $recording))->apply(
                $this->lease(),
                $this->pveCommit(
                    MonitoringRunKind::ObservedTasks,
                    [$this->pveArchiveScope(MonitoringScopeStatus::Complete)],
                    tasks: [$this->pveTask($source, $endTime, $remoteStatus)],
                ),
            );

            self::assertSame(
                $expectedLifecycle,
                $this->insertValues($recording, 'pve_observed_backup_tasks')['lifecycle'],
            );
        }
    }

    public function testFailedCommitValidationRejectsEachInvalidDisjunctionOperand(): void
    {
        $failedCommit = $this->pveCommit(
            MonitoringRunKind::ObservedTasks,
            [$this->pveArchiveScope(MonitoringScopeStatus::Failed)],
        );
        $invalid = [
            [$this->pveCommit(MonitoringRunKind::ObservedTasks, [$this->pveArchiveScope(MonitoringScopeStatus::Complete)]), 'valid_code'],
            [$failedCommit, ''],
            [$failedCommit, str_repeat('a', 65)],
            [$failedCommit, 'INVALID'],
        ];

        foreach ($invalid as [$commit, $errorCode]) {
            try {
                $this->store($this->database())->finishFailedCommit($this->lease(), $commit, $errorCode);
                self::fail('The invalid failed-commit contract was accepted.');
            } catch (MonitoringConflict $conflict) {
                self::assertSame('The failed monitoring commit contract is invalid.', $conflict->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $configuration */
    private function database(
        array $configuration = [],
        ?MonitoringStoreRecording $recording = null,
    ): Connection&MockObject {
        $binding = $configuration['binding'] ?? InstallationBinding::pveStandalone('pve-a');
        self::assertInstanceOf(InstallationBinding::class, $binding);
        $product = $configuration['product'] ?? $binding->product->value;
        $configuration += [
            'now' => self::NOW,
            'product' => $product,
            'binding' => $binding,
            'run_status' => 'running',
            'run_applied_at' => null,
            'run_update_count' => 1,
            'pve_job' => false,
            'pve_task' => false,
            'pbs_job' => false,
            'pbs_task' => false,
            'selected_count' => 1,
        ];
        $recording ??= new MonitoringStoreRecording();
        $database = $this->createMock(Connection::class);
        $database->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($database),
        );
        $database->method('fetchOne')->willReturnCallback(
            function (string $sql) use ($configuration): mixed {
                if ('SELECT UTC_TIMESTAMP(6)' === $sql) {
                    return $configuration['now'];
                }
                if (str_contains($sql, 'inventory_sync_endpoint_attempts')) {
                    return $configuration['selected_count'];
                }
                if (str_contains($sql, 'pbs_servers')) {
                    return $this->id('pbs-server')->binary();
                }
                return 0;
            },
        );
        $database->method('fetchAssociative')->willReturnCallback(
            function (string $sql) use ($configuration): array|false {
                $binding = $configuration['binding'];
                self::assertInstanceOf(InstallationBinding::class, $binding);
                if (str_contains($sql, 'collector_schedule')) {
                    return [
                        'lease_owner' => $this->id('worker')->binary(),
                        'lease_token' => $this->id('cycle')->binary(),
                        'lease_fencing_token' => 7,
                        'lease_expires_at' => '2099-01-01 00:00:00.000000',
                    ];
                }
                if (str_contains($sql, 'collector_cycles')) {
                    return [
                        'status' => 'running',
                        'worker_instance_id' => $this->id('worker')->binary(),
                        'worker_kind' => 'collector',
                        'fencing_token' => 7,
                    ];
                }
                if (str_contains($sql, 'inventory_sync_runs')) {
                    return [
                        'status' => 'succeeded',
                        'applied_at' => self::NOW,
                        'endpoint_id' => $this->endpoint()->bytes,
                        'expected_connection_revision' => 1,
                        'cycle_token' => $this->id('cycle')->binary(),
                        'collector_fencing_token' => 7,
                    ];
                }
                if (str_contains($sql, 'proxmox_monitoring_runs')) {
                    return [
                        'connection_id' => $this->id('connection')->binary(),
                        'parent_sync_run_id' => $this->id('parent')->binary(),
                        'product' => $configuration['product'],
                        'binding_kind' => $binding->kind->value,
                        'binding_value' => $binding->identity,
                        'binding_legacy_endpoint_id' => $binding->legacyEndpointId?->bytes,
                        'monitoring_kind' => $configuration['kind'] ?? 'observed_tasks',
                        'expected_connection_revision' => 1,
                        'endpoint_id' => $this->endpoint()->bytes,
                        'cycle_token' => $this->id('cycle')->binary(),
                        'collector_fencing_token' => 7,
                        'status' => $configuration['run_status'],
                        'applied_at' => $configuration['run_applied_at'],
                    ];
                }
                if (str_contains($sql, 'proxmox_connections')) {
                    return ['product' => $configuration['product'], 'enabled' => 1, 'revision' => 1];
                }
                if (str_contains($sql, 'proxmox_installation_bindings')) {
                    return [
                        'product' => $configuration['product'],
                        'identity_kind' => $binding->kind->value,
                        'identity_value' => $binding->identity,
                        'legacy_endpoint_id' => $binding->legacyEndpointId?->bytes,
                    ];
                }
                if (str_contains($sql, 'pve_external_backup_jobs')) {
                    $row = $configuration['pve_job'];
                    assert(false === $row || is_array($row));
                    return $row;
                }
                if (str_contains($sql, 'pve_observed_backup_tasks')) {
                    $row = $configuration['pve_task'];
                    assert(false === $row || is_array($row));
                    return $row;
                }
                if (str_contains($sql, 'pbs_external_jobs')) {
                    $row = $configuration['pbs_job'];
                    assert(false === $row || is_array($row));
                    return $row;
                }
                if (str_contains($sql, 'pbs_observed_tasks')) {
                    $row = $configuration['pbs_task'];
                    assert(false === $row || is_array($row));
                    return $row;
                }
                return false;
            },
        );
        $database->method('insert')->willReturnCallback(
            static function (string $table, array $values) use ($recording): int {
                /** @var array<string, mixed> $values */
                $recording->inserts[] = [$table, $values];
                return 1;
            },
        );
        $database->method('update')->willReturnCallback(
            static function (string $table, array $values, array $criteria) use ($configuration, $recording): int {
                /** @var array<string, mixed> $values */
                /** @var array<string, mixed> $criteria */
                $recording->updates[] = [$table, $values, $criteria];
                $count = 'proxmox_monitoring_runs' === $table ? $configuration['run_update_count'] : 1;
                assert(is_int($count));
                return $count;
            },
        );
        $database->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters) use ($recording): int {
                /** @var array<string, mixed> $parameters */
                $recording->statements[] = [$sql, $parameters];
                return 1;
            },
        );
        return $database;
    }

    private function store(Connection $connection): DbalMonitoringRunStore
    {
        return new DbalMonitoringRunStore($connection, new MonitoringStoreSequenceIds());
    }

    /** @return array<string, mixed> */
    private function insertValues(MonitoringStoreRecording $recording, string $table): array
    {
        foreach ($recording->inserts as [$recordedTable, $values]) {
            if ($table === $recordedTable) {
                return $values;
            }
        }

        self::fail(sprintf('No insert into %s was recorded.', $table));
    }

    /** @return array<string, mixed> */
    private function updateValues(MonitoringStoreRecording $recording, string $table): array
    {
        foreach ($recording->updates as [$recordedTable, $values]) {
            if ($table === $recordedTable) {
                return $values;
            }
        }

        self::fail(sprintf('No update of %s was recorded.', $table));
    }

    /** @return array<string, mixed> */
    private function updateCriteria(MonitoringStoreRecording $recording, string $table): array
    {
        foreach ($recording->updates as [$recordedTable, , $criteria]) {
            if ($table === $recordedTable) {
                return $criteria;
            }
        }

        self::fail(sprintf('No update of %s was recorded.', $table));
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedScopeInsert(
        int $generatedId,
        MonitoringScopeType $type,
        string $scopeKey,
        MonitoringSourceKind $source,
        ?string $filter,
        MonitoringScopeStatus $status,
        bool $window,
        ?string $errorCode,
    ): array {
        return [
            'id' => $this->generatedId($generatedId),
            'connection_id' => $this->id('connection')->binary(),
            'monitoring_run_id' => $this->id('run')->binary(),
            'scope_type' => $type->value,
            'scope_key' => $scopeKey,
            'source_kind' => $source->value,
            'filter_value' => $filter ?? '@none',
            'status' => $status->value,
            'window_since' => $window ? '2026-07-12 08:00:00.000000' : null,
            'window_until' => $window ? self::NOW : null,
            'pages_read' => 1,
            'rows_read' => 1,
            'items_seen' => 1,
            'truncated' => 0,
            'history_gap' => 0,
            'error_code' => $errorCode,
            'observed_at' => self::NOW,
        ];
    }

    private function generatedId(int $sequence): string
    {
        return substr(hash('sha256', 'monitoring-store-'.$sequence, true), 0, 16);
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function epoch(int $seconds): string
    {
        return (new DateTimeImmutable('@'.$seconds))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId($this->id('worker')->binary()),
            new CollectorCycleToken($this->id('cycle')->binary()),
            7,
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
        );
    }

    private function start(): MonitoringRunStart
    {
        return new MonitoringRunStart(
            $this->id('run'), $this->id('parent'), $this->id('connection'), $this->endpoint(),
            ProxmoxProduct::Pve, InstallationBinding::pveStandalone('pve-a'),
            MonitoringRunKind::ObservedTasks, 1, $this->now(),
        );
    }

    /**
     * @param list<MonitoringScopeResult> $scopes
     * @param list<PveBackupJob> $jobs
     * @param list<PveBackupTask> $tasks
     */
    private function pveCommit(
        MonitoringRunKind $kind,
        array $scopes,
        array $jobs = [],
        array $tasks = [],
    ): MonitoringCommit {
        return new MonitoringCommit(
            $this->id('run'), $this->id('parent'), $this->id('connection'), $this->endpoint(),
            ProxmoxProduct::Pve, InstallationBinding::pveStandalone('pve-a'), $kind, 1,
            $scopes, $jobs, $tasks, [], [], $this->now(),
        );
    }

    /**
     * @param list<MonitoringScopeResult> $scopes
     * @param list<PbsJobObservation> $jobs
     * @param list<PbsTaskObservation> $tasks
     */
    private function pbsCommit(
        MonitoringRunKind $kind,
        array $scopes,
        array $jobs = [],
        array $tasks = [],
        ?InstallationBinding $binding = null,
    ): MonitoringCommit {
        $binding ??= InstallationBinding::pbsLegacyNode('pbs-a', $this->endpoint());
        return new MonitoringCommit(
            $this->id('run'), $this->id('parent'), $this->id('connection'), $this->endpoint(),
            ProxmoxProduct::Pbs, $binding, $kind, 1,
            $scopes, [], [], $jobs, $tasks, $this->now(),
        );
    }

    private function pveJob(): PveBackupJob
    {
        return new PveBackupJob(
            'job-a', 'daily', true, true, 'daily backup', 1_759_061_000,
            'pve-a', 'backup', '101', false, 'snapshot', 'zstd', null, null,
        );
    }

    private function pveTask(PveTaskSource $source, ?int $endTime, ?string $status): PveBackupTask
    {
        return new PveBackupTask(
            PveUpid::parse('UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:observer@pve:'),
            $source,
            $endTime,
            $status,
        );
    }

    /** @return array<string, mixed> */
    private function pveTaskRow(string $lifecycle, ?string $finishedAt, ?string $status): array
    {
        $task = $this->pveTask(PveTaskSource::Archive, 1_728_053_300, 'OK');
        return [
            'id' => $this->id('pve-task-row')->binary(),
            'upid_raw' => $task->upid->raw,
            'seen_active' => 0,
            'seen_archive' => 1,
            'lifecycle' => $lifecycle,
            'finished_at' => $finishedAt,
            'remote_status' => $status,
        ];
    }

    private function pbsJob(): PbsJobObservation
    {
        $upid = new PbsUpid('UPID:pbs-a:0000002A:000F4240:AAAAAAAAAAAAAAAA:68D927C0:prunejob:prune-a:root@pam:');
        return new PbsJobObservation(
            PbsJobKind::Prune,
            new PbsJobId('prune-a'),
            new PbsDatastoreId('store-a'),
            null,
            'daily',
            false,
            null,
            null,
            null,
            null,
            $upid,
            PbsTaskOutcome::Ok,
            null,
            1_759_061_000,
        );
    }

    private function pbsTask(?PbsTaskOutcome $outcome, ?int $endTime, ?string $node = 'localhost'): PbsTaskObservation
    {
        return new PbsTaskObservation(
            new PbsUpid('UPID:pbs-a:0000002A:000F4240:FFFFFFFFFFFFFFFF:68D927C0:backup:store-a:root@pam:'),
            $node,
            true,
            true,
            $outcome,
            $endTime,
        );
    }

    private function pveJobScope(): MonitoringScopeResult
    {
        return $this->scope(MonitoringScopeType::PveBackupJobs, MonitoringSourceKind::Jobs, null);
    }

    private function pveArchiveScope(MonitoringScopeStatus $status): MonitoringScopeResult
    {
        return $this->scope(
            MonitoringScopeType::PveTasksArchive,
            MonitoringSourceKind::Archive,
            'vzdump',
            $status,
            true,
        );
    }

    private function pbsJobScope(): MonitoringScopeResult
    {
        return $this->scope(MonitoringScopeType::PbsPruneJobs, MonitoringSourceKind::Jobs, 'prune');
    }

    private function pbsRunningScope(): MonitoringScopeResult
    {
        return $this->scope(MonitoringScopeType::PbsTasksRunning, MonitoringSourceKind::Running, 'backup');
    }

    /** @return list<MonitoringScopeResult> */
    private function pbsHistoryScopes(): array
    {
        return array_map(
            fn (PbsTaskFilterFamily $family): MonitoringScopeResult => $this->scope(
                MonitoringScopeType::PbsTasksWindow,
                MonitoringSourceKind::History,
                $family->value,
                MonitoringScopeStatus::Complete,
                true,
            ),
            PbsTaskFilterFamily::cases(),
        );
    }

    private function scope(
        MonitoringScopeType $type,
        MonitoringSourceKind $source,
        ?string $filter,
        MonitoringScopeStatus $status = MonitoringScopeStatus::Complete,
        bool $window = false,
    ): MonitoringScopeResult {
        return new MonitoringScopeResult(
            $type,
            str_starts_with($type->value, 'pbs_tasks_') ? 'pbs-a' : '@installation',
            $source,
            $filter,
            $status,
            $window ? new DateTimeImmutable('2026-07-12T08:00:00Z') : null,
            $window ? $this->now() : null,
            1,
            1,
            1,
            false,
            false,
            MonitoringScopeStatus::Complete === $status ? null : 'read_failed',
            $this->now(),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-12T09:00:00Z');
    }

    private function endpoint(): EndpointId
    {
        return new EndpointId($this->id('endpoint')->binary());
    }

    private function id(string $seed): InventoryIdentifier
    {
        return new InventoryIdentifier(substr(hash('sha256', $seed, true), 0, 16));
    }
}

final class MonitoringStoreRecording
{
    /** @var list<array{string, array<string, mixed>}> */ public array $inserts = [];
    /** @var list<array{string, array<string, mixed>, array<string, mixed>}> */ public array $updates = [];
    /** @var list<array{string, array<string, mixed>}> */ public array $statements = [];
}

final class MonitoringStoreSequenceIds implements InventoryIdentifierGenerator
{
    private int $next = 0;

    public function generate(): InventoryIdentifier
    {
        return new InventoryIdentifier(substr(hash('sha256', 'monitoring-store-'.$this->next++, true), 0, 16));
    }
}
