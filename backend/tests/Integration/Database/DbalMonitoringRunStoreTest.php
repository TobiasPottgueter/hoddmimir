<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
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
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveUpid;
use App\Infrastructure\Persistence\MariaDb\DbalMonitoringRunStore;
use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;

final class DbalMonitoringRunStoreTest extends DatabaseTestCase
{
    private InventoryIdentifier $connectionId;
    private InventoryIdentifier $parentRunId;
    private EndpointId $endpointId;
    private CollectorLease $lease;
    private DbalMonitoringRunStore $store;
    private InstallationBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionId = self::id('connection');
        $this->parentRunId = self::id('parent');
        $this->endpointId = self::endpoint('endpoint');
        $this->binding = InstallationBinding::pveStandalone('pve-a');
        $this->lease = new CollectorLease(
            new CollectorWorkerId(self::bytes('worker')),
            new CollectorCycleToken(self::bytes('cycle')),
            7,
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
        );
        $this->store = new DbalMonitoringRunStore(
            $this->connection(),
            new MonitoringSequentialIdentifierGenerator(),
        );
        $this->seedUsableParent();
    }

    public function testBeginAndApplyCompleteChildExactlyOnceWithoutChangingParent(): void
    {
        $run = self::id('child-complete');
        $this->begin($run, MonitoringRunKind::ExternalJobs);
        $result = $this->store->apply($this->lease, $this->commit(
            $run,
            MonitoringRunKind::ExternalJobs,
            [$this->jobsScope(MonitoringScopeStatus::Complete)],
        ));

        self::assertSame('succeeded', $result->status->value);
        self::assertSame(
            ['status' => 'succeeded', 'applied' => 1, 'scopes_seen' => 1],
            $this->connection()->fetchAssociative(
                'SELECT status, applied_at IS NOT NULL AS applied, scopes_seen FROM proxmox_monitoring_runs WHERE id = :id',
                ['id' => $run->binary()],
            ),
        );
        self::assertSame(
            ['status' => 'succeeded', 'authoritative' => 1],
            $this->connection()->fetchAssociative(
                'SELECT status, authoritative FROM inventory_sync_runs WHERE id = :id',
                ['id' => $this->parentRunId->binary()],
            ),
        );

        $this->expectException(CollectorLeaseOwnershipLost::class);
        $this->store->apply($this->lease, $this->commit(
            $run,
            MonitoringRunKind::ExternalJobs,
            [$this->jobsScope(MonitoringScopeStatus::Complete)],
        ));
    }

    public function testFailedCommitPersistsScopesButNoProjectionCursorOrAppliedMarker(): void
    {
        $run = self::id('child-failed');
        $this->begin($run, MonitoringRunKind::ObservedTasks);
        $scope = $this->archiveScope(MonitoringScopeStatus::Failed, historyGap: true);
        $this->store->finishFailedCommit(
            $this->lease,
            $this->commit($run, MonitoringRunKind::ObservedTasks, [$scope]),
            'all_streams_failed',
        );

        self::assertSame(
            ['status' => 'failed', 'error_code' => 'all_streams_failed', 'applied_at' => null],
            $this->connection()->fetchAssociative(
                'SELECT status, error_code, applied_at FROM proxmox_monitoring_runs WHERE id = :id',
                ['id' => $run->binary()],
            ),
        );
        self::assertSame(1, $this->rowCount(
            'SELECT COUNT(*) FROM proxmox_monitoring_scope_results WHERE monitoring_run_id = :id',
            ['id' => $run->binary()],
        ));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM proxmox_monitoring_cursors'));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM pve_observed_backup_tasks'));
    }

    public function testCursorAdvancesOnlyForCompleteGapFreeArchiveWindow(): void
    {
        $completeRun = self::id('cursor-complete');
        $this->begin($completeRun, MonitoringRunKind::ObservedTasks);
        $this->store->apply($this->lease, $this->commit(
            $completeRun,
            MonitoringRunKind::ObservedTasks,
            [$this->archiveScope(MonitoringScopeStatus::Complete)],
        ));
        $before = $this->connection()->fetchOne(
            'SELECT completed_until FROM proxmox_monitoring_cursors WHERE connection_id = :connection',
            ['connection' => $this->connectionId->binary()],
        );
        self::assertSame('2026-07-11 21:00:00.000000', $before);

        $this->rotateParent('cursor-partial-parent');
        $partialRun = self::id('cursor-partial');
        $this->begin($partialRun, MonitoringRunKind::ObservedTasks);
        $this->store->apply($this->lease, $this->commit(
            $partialRun,
            MonitoringRunKind::ObservedTasks,
            [$this->archiveScope(MonitoringScopeStatus::Partial)],
        ));
        self::assertSame($before, $this->connection()->fetchOne(
            'SELECT completed_until FROM proxmox_monitoring_cursors WHERE connection_id = :connection',
            ['connection' => $this->connectionId->binary()],
        ));
    }

    public function testTaskStateIsMonotoneAndConflictingTerminalObservationMakesChildPartial(): void
    {
        $runningRun = self::id('task-running');
        $this->begin($runningRun, MonitoringRunKind::ObservedTasks);
        $this->store->apply($this->lease, $this->commit(
            $runningRun,
            MonitoringRunKind::ObservedTasks,
            [$this->archiveScope(MonitoringScopeStatus::Complete)],
            [self::task(PveTaskSource::Active, null, 'RUNNING')],
        ));

        $this->rotateParent('task-unknown-parent');
        $unknownRun = self::id('task-unknown');
        $this->begin($unknownRun, MonitoringRunKind::ObservedTasks);
        $this->store->apply($this->lease, $this->commit(
            $unknownRun,
            MonitoringRunKind::ObservedTasks,
            [$this->archiveScope(MonitoringScopeStatus::Complete)],
            [self::task(PveTaskSource::Archive, null, null)],
        ));
        self::assertSame('running', $this->connection()->fetchOne('SELECT lifecycle FROM pve_observed_backup_tasks'));

        $this->rotateParent('task-terminal-parent');
        $terminalRun = self::id('task-terminal');
        $this->begin($terminalRun, MonitoringRunKind::ObservedTasks);
        $this->store->apply($this->lease, $this->commit(
            $terminalRun,
            MonitoringRunKind::ObservedTasks,
            [$this->archiveScope(MonitoringScopeStatus::Complete)],
            [self::task(PveTaskSource::Archive, 1728053300, 'OK')],
        ));
        self::assertSame(
            ['lifecycle' => 'stopped', 'remote_status' => 'OK'],
            $this->connection()->fetchAssociative(
                'SELECT lifecycle, remote_status FROM pve_observed_backup_tasks',
            ),
        );

        $this->rotateParent('task-conflict-parent');
        $conflictRun = self::id('task-conflict');
        $this->begin($conflictRun, MonitoringRunKind::ObservedTasks);
        $result = $this->store->apply($this->lease, $this->commit(
            $conflictRun,
            MonitoringRunKind::ObservedTasks,
            [$this->archiveScope(MonitoringScopeStatus::Complete)],
            [self::task(PveTaskSource::Archive, 1728053400, 'ERROR')],
        ));
        self::assertSame('partial', $result->status->value);
        self::assertSame(1, $result->conflicts);
        self::assertSame('OK', $this->connection()->fetchOne('SELECT remote_status FROM pve_observed_backup_tasks'));
    }

    public function testFailureTerminalizesRunningChildAfterConnectionOrBindingDrift(): void
    {
        $connectionRun = self::id('drift-connection');
        $this->begin($connectionRun, MonitoringRunKind::ExternalJobs);
        $this->connection()->update('proxmox_connections', ['revision' => 2], [
            'id' => $this->connectionId->binary(),
        ]);
        $this->store->fail($this->lease, new MonitoringRunFailure(
            $connectionRun, $this->connectionId, 'read_failed', new DateTimeImmutable('2026-07-11T21:00:00Z'),
        ));
        self::assertSame('connection_changed', $this->errorCode($connectionRun));

        $this->connection()->update('proxmox_connections', ['revision' => 1], [
            'id' => $this->connectionId->binary(),
        ]);
        $this->rotateParent('drift-binding-parent');
        $bindingRun = self::id('drift-binding');
        $this->begin($bindingRun, MonitoringRunKind::ExternalJobs);
        $this->connection()->update('proxmox_installation_bindings', ['identity_value' => 'pve-b'], [
            'connection_id' => $this->connectionId->binary(),
        ]);
        $this->store->fail($this->lease, new MonitoringRunFailure(
            $bindingRun, $this->connectionId, 'read_failed', new DateTimeImmutable('2026-07-11T21:00:01Z'),
        ));
        self::assertSame('binding_changed', $this->errorCode($bindingRun));

        $this->expectException(CollectorLeaseOwnershipLost::class);
        $this->store->fail($this->lease, new MonitoringRunFailure(
            $bindingRun, $this->connectionId, 'read_failed', new DateTimeImmutable('2026-07-11T21:00:02Z'),
        ));
    }

    public function testPbsJobProjectionCreatesThenUpdatesOneStableIdentity(): void
    {
        $this->switchToPbs();
        $firstRun = self::id('pbs-job-first');
        $this->begin($firstRun, MonitoringRunKind::ExternalJobs);
        $first = $this->store->apply($this->lease, $this->pbsCommit(
            $firstRun,
            MonitoringRunKind::ExternalJobs,
            [$this->pbsJobScope(PbsJobKind::Prune)],
            jobs: [$this->pbsJob(false, 'daily')],
        ));
        self::assertSame([1, 0], [$first->created, $first->updated]);
        $before = $this->connection()->fetchAssociative(
            'SELECT first_seen_run_id, config_hash FROM pbs_external_jobs',
        );
        self::assertIsArray($before);

        $this->rotateParent('pbs-job-update-parent');
        $secondRun = self::id('pbs-job-second');
        $this->begin($secondRun, MonitoringRunKind::ExternalJobs);
        $second = $this->store->apply($this->lease, $this->pbsCommit(
            $secondRun,
            MonitoringRunKind::ExternalJobs,
            [$this->pbsJobScope(PbsJobKind::Prune)],
            jobs: [$this->pbsJob(true, 'hourly')],
        ));

        self::assertSame([0, 1], [$second->created, $second->updated]);
        $after = $this->connection()->fetchAssociative(
            'SELECT enabled, raw_schedule, first_seen_run_id, last_seen_run_id, config_hash FROM pbs_external_jobs',
        );
        self::assertIsArray($after);
        self::assertSame(0, $after['enabled']);
        self::assertSame('hourly', $after['raw_schedule']);
        self::assertSame($before['first_seen_run_id'], $after['first_seen_run_id']);
        self::assertSame($secondRun->binary(), $after['last_seen_run_id']);
        self::assertNotSame($before['config_hash'], $after['config_hash']);
    }

    public function testPbsInspectionsPersistWithFenceAndReadWithoutCredentials(): void
    {
        $this->switchToPbs();
        $run = self::id('pbs-inspection');
        $this->begin($run, MonitoringRunKind::ObservedTasks);
        $task = $this->pbsTask(true, false, 'localhost', null, null);
        $inspection = new \App\Application\Proxmox\Pbs\PbsTaskInspection($task->upid, 'running', null, null,
            [['number' => 1, 'text' => 'password=[REDACTED]']], true, null, null);
        $this->store->apply($this->lease, $this->pbsCommit($run, MonitoringRunKind::ObservedTasks,
            [$this->pbsTaskScope(PbsTaskFilterFamily::Backup, false)], tasks: [$task], inspections: [$inspection]));
        $reader = new \App\Infrastructure\Persistence\MariaDb\DbalPbsTaskReadModel($this->connection());
        $page = $reader->tasks(null, 0);
        self::assertSame(1, $page['total']);
        $id = $page['items'][0]['id']; self::assertIsString($id);
        $detail = $reader->detail(new \App\Application\Inventory\ReadModel\ReadModelIdentifier($id));
        self::assertNotNull($detail);
        self::assertSame($task->upid->value, $detail['upid']);
        self::assertIsArray($detail['inspection']);
        self::assertSame([['number' => 1, 'text' => 'password=[REDACTED]']], $detail['inspection']['lines']);
        self::assertTrue($detail['inspection']['truncated']);
        self::assertSame('2026-07-11T21:00:00.000000Z', $detail['inspectedAt']);
        self::assertSame([], $reader->tasks(null, 50)['items']);
        $missing = new \App\Application\Inventory\ReadModel\ReadModelIdentifier('00000000-0000-0000-0000-000000000000');
        self::assertSame(0, $reader->tasks($missing, 0)['total']);
        self::assertNull($reader->detail($missing));
        $this->rotateParent('pbs-inspection-preserve');
        $next = self::id('pbs-inspection-no-refetch');
        $this->begin($next, MonitoringRunKind::ObservedTasks);
        $this->store->apply($this->lease, $this->pbsCommit($next, MonitoringRunKind::ObservedTasks,
            [$this->pbsTaskScope(PbsTaskFilterFamily::Backup, false)], tasks: [$task]));
        self::assertSame($detail, $reader->detail(new \App\Application\Inventory\ReadModel\ReadModelIdentifier($id)));
    }

    public function testPbsTaskProjectionMergesPassProvenanceReportedNodeAndTerminalStateMonotonically(): void
    {
        $this->switchToPbs();
        $runningRun = self::id('pbs-task-running');
        $this->begin($runningRun, MonitoringRunKind::ObservedTasks);
        $this->store->apply($this->lease, $this->pbsCommit(
            $runningRun,
            MonitoringRunKind::ObservedTasks,
            [$this->pbsTaskScope(PbsTaskFilterFamily::Backup, false)],
            tasks: [$this->pbsTask(true, false, 'localhost', null, null)],
        ));

        $this->rotateParent('pbs-task-terminal-parent');
        $terminalRun = self::id('pbs-task-terminal');
        $this->begin($terminalRun, MonitoringRunKind::ObservedTasks);
        $this->store->apply($this->lease, $this->pbsCommit(
            $terminalRun,
            MonitoringRunKind::ObservedTasks,
            [$this->pbsTaskScope(PbsTaskFilterFamily::Backup, true)],
            tasks: [
                $this->pbsTask(false, true, 'pbs-a', PbsTaskOutcome::Ok, null),
                new PbsTaskObservation(
                    new PbsUpid('UPID:pbs-a:0000002B:000F4241:EEEEEEEEEEEEEEEE:68D927C1:backup:store_b:root@pam:'),
                    'localhost',
                    false,
                    true,
                    null,
                    null,
                ),
            ],
        ));

        self::assertSame(
            [
                'reported_node_name' => 'pbs-a',
                'seen_running' => 1,
                'seen_history' => 1,
                'lifecycle' => 'stopped',
                'remote_status' => 'ok',
                'finished_at' => null,
            ],
            $this->connection()->fetchAssociative(
                "SELECT reported_node_name, seen_running, seen_history, lifecycle, remote_status, finished_at FROM pbs_observed_tasks WHERE worker_id = 'store_a'",
            ),
        );
        self::assertSame(
            ['seen_running' => 0, 'seen_history' => 1, 'lifecycle' => 'running'],
            $this->connection()->fetchAssociative(
                "SELECT seen_running, seen_history, lifecycle FROM pbs_observed_tasks WHERE worker_id = 'store_b'",
            ),
        );

        $this->rotateParent('pbs-task-conflict-parent');
        $conflictRun = self::id('pbs-task-conflict');
        $this->begin($conflictRun, MonitoringRunKind::ObservedTasks);
        $conflict = $this->store->apply($this->lease, $this->pbsCommit(
            $conflictRun,
            MonitoringRunKind::ObservedTasks,
            [$this->pbsTaskScope(PbsTaskFilterFamily::Backup, true)],
            tasks: [$this->pbsTask(false, true, 'localhost', PbsTaskOutcome::Error, 1_759_061_954)],
        ));
        self::assertSame('partial', $conflict->status->value);
        self::assertSame(1, $conflict->conflicts);
        self::assertSame(
            ['reported_node_name' => 'pbs-a', 'remote_status' => 'ok'],
            $this->connection()->fetchAssociative(
                "SELECT reported_node_name, remote_status FROM pbs_observed_tasks WHERE worker_id = 'store_a'",
            ),
        );
    }

    public function testPbsJobLastRunIsMonotoneAndSameUpidOnlyEnrichesMissingEndTime(): void
    {
        $this->switchToPbs();
        $currentUpid = $this->pbsJobUpid('68D927C2');
        $firstRun = self::id('pbs-last-run-first');
        $this->begin($firstRun, MonitoringRunKind::ExternalJobs);
        $this->store->apply($this->lease, $this->pbsCommit(
            $firstRun,
            MonitoringRunKind::ExternalJobs,
            [$this->pbsJobScope(PbsJobKind::Prune)],
            jobs: [$this->pbsJob(false, 'daily', $currentUpid, PbsTaskOutcome::Ok, null)],
        ));

        $updates = [
            ['null', null, null, null],
            ['older', $this->pbsJobUpid('68D927C1'), PbsTaskOutcome::Error, (int) hexdec('68D927C1') + 10],
            ['same', $currentUpid, PbsTaskOutcome::Error, (int) hexdec('68D927C2') + 10],
        ];
        foreach ($updates as [$label, $upid, $outcome, $endTime]) {
            $this->rotateParent('pbs-last-run-'.$label.'-parent');
            $run = self::id('pbs-last-run-'.$label);
            $this->begin($run, MonitoringRunKind::ExternalJobs);
            $this->store->apply($this->lease, $this->pbsCommit(
                $run,
                MonitoringRunKind::ExternalJobs,
                [$this->pbsJobScope(PbsJobKind::Prune)],
                jobs: [$this->pbsJob(false, 'daily', $upid, $outcome, $endTime)],
            ));
        }
        self::assertSame(
            [
                'last_run_upid' => $currentUpid->value,
                'last_run_state' => 'ok',
                'last_run_end_at' => '2025-09-28 12:19:24.000000',
            ],
            $this->connection()->fetchAssociative(
                'SELECT last_run_upid, last_run_state, last_run_end_at FROM pbs_external_jobs',
            ),
        );

        $this->rotateParent('pbs-last-run-newer-parent');
        $newerRun = self::id('pbs-last-run-newer');
        $newerUpid = $this->pbsJobUpid('68D927C3');
        $this->begin($newerRun, MonitoringRunKind::ExternalJobs);
        $this->store->apply($this->lease, $this->pbsCommit(
            $newerRun,
            MonitoringRunKind::ExternalJobs,
            [$this->pbsJobScope(PbsJobKind::Prune)],
            jobs: [$this->pbsJob(
                false, 'daily', $newerUpid, PbsTaskOutcome::Warning, (int) hexdec('68D927C3') + 20,
            )],
        ));
        self::assertSame($newerUpid->value, $this->connection()->fetchOne(
            'SELECT last_run_upid FROM pbs_external_jobs',
        ));
        self::assertSame('warning', $this->connection()->fetchOne(
            'SELECT last_run_state FROM pbs_external_jobs',
        ));
    }

    public function testPbsHistoryCursorRequiresAllFourCompleteMatchingWindows(): void
    {
        $this->switchToPbs();
        $completeRun = self::id('pbs-cursor-complete');
        $this->begin($completeRun, MonitoringRunKind::ObservedTasks);
        $this->store->apply($this->lease, $this->pbsCommit(
            $completeRun,
            MonitoringRunKind::ObservedTasks,
            $this->pbsHistoryScopes(),
        ));
        $initial = $this->connection()->fetchOne(
            'SELECT completed_until FROM proxmox_monitoring_cursors WHERE connection_id = :connection',
            ['connection' => $this->connectionId->binary()],
        );
        self::assertSame('2026-07-11 21:00:00.000000', $initial);

        $variants = [
            'missing' => array_slice($this->pbsHistoryScopes('2026-07-11T21:00:00Z', '2026-07-11T22:00:00Z'), 0, 3),
            'partial' => $this->pbsHistoryScopes(
                '2026-07-11T21:00:00Z', '2026-07-11T22:00:00Z', PbsTaskFilterFamily::Prune,
            ),
            'failed' => $this->pbsHistoryScopes(
                '2026-07-11T21:00:00Z', '2026-07-11T22:00:00Z', PbsTaskFilterFamily::Sync, true,
            ),
            'mismatch' => $this->pbsHistoryScopes(
                '2026-07-11T21:00:00Z', '2026-07-11T22:00:00Z', null, false, PbsTaskFilterFamily::Verify,
            ),
        ];
        foreach ($variants as $label => $scopes) {
            $this->rotateParent('pbs-cursor-'.$label.'-parent');
            $run = self::id('pbs-cursor-'.$label);
            $this->begin($run, MonitoringRunKind::ObservedTasks);
            $this->store->apply($this->lease, $this->pbsCommit(
                $run,
                MonitoringRunKind::ObservedTasks,
                $scopes,
            ));
            self::assertSame($initial, $this->connection()->fetchOne(
                'SELECT completed_until FROM proxmox_monitoring_cursors WHERE connection_id = :connection',
                ['connection' => $this->connectionId->binary()],
            ), $label);
        }
    }

    public function testPbsTaskHashCollisionRollsBackTheMonitoringApply(): void
    {
        $this->switchToPbs();
        $run = self::id('pbs-task-collision');
        $this->begin($run, MonitoringRunKind::ObservedTasks);
        $incoming = $this->pbsTask(true, false, 'localhost', null, null);
        $seededRaw = 'UPID:pbs-a:0000002C:000F4242:DDDDDDDDDDDDDDDD:68D927C2:backup:store_collision:root@pam:';
        $this->connection()->insert('pbs_observed_tasks', [
            'id' => self::bytes('pbs-task-collision-row'),
            'connection_id' => $this->connectionId->binary(),
            'server_id' => self::bytes('pbs-server'),
            'upid_hash' => hash('sha256', $incoming->upid->value, true),
            'upid_raw' => $seededRaw,
            'upid_node_name' => 'pbs-a',
            'reported_node_name' => 'localhost',
            'pid_hex' => '0000002c',
            'pstart_hex' => '000f4242',
            'task_id_hex' => 'dddddddddddddddd',
            'starttime_hex' => '68d927c2',
            'worker_type' => 'backup',
            'worker_id' => 'store_collision',
            'auth_id' => 'root@pam',
            'seen_running' => 1,
            'seen_history' => 0,
            'lifecycle' => 'running',
            'remote_status' => null,
            'started_at' => '2025-09-28 12:19:14.000000',
            'finished_at' => null,
            'first_seen_run_id' => $run->binary(),
            'last_seen_run_id' => $run->binary(),
            'first_seen_at' => '2026-07-11 21:00:00.000000',
            'last_seen_at' => '2026-07-11 21:00:00.000000',
        ]);

        try {
            $this->store->apply($this->lease, $this->pbsCommit(
                $run,
                MonitoringRunKind::ObservedTasks,
                [$this->pbsTaskScope(PbsTaskFilterFamily::Backup, false)],
                tasks: [$incoming],
            ));
            self::fail('The seeded PBS task hash/raw collision was accepted.');
        } catch (MonitoringConflict) {
            self::assertSame('running', $this->connection()->fetchOne(
                'SELECT status FROM proxmox_monitoring_runs WHERE id = :id',
                ['id' => $run->binary()],
            ));
            self::assertSame($seededRaw, $this->connection()->fetchOne(
                'SELECT upid_raw FROM pbs_observed_tasks',
            ));
        }
        try {
            $this->connection()->update('pbs_observed_tasks', ['worker_type' => 'tape-backup'], [
                'id' => self::bytes('pbs-task-collision-row'),
            ]);
            self::fail('The PBS persisted-task worker allowlist constraint was bypassed.');
        } catch (DbalException $failure) {
            self::assertStringContainsString('chk_pbs_observed_tasks_worker_type', $failure->getMessage());
        }
    }

    private function begin(InventoryIdentifier $run, MonitoringRunKind $kind): void
    {
        $this->store->begin($this->lease, new MonitoringRunStart(
            $run,
            $this->parentRunId,
            $this->connectionId,
            $this->endpointId,
            $this->binding->product,
            $this->binding,
            $kind,
            1,
            new DateTimeImmutable('2026-07-11T20:00:00Z'),
        ));
    }

    /**
     * @param list<MonitoringScopeResult> $scopes
     * @param list<PbsJobObservation>     $jobs
     * @param list<PbsTaskObservation>    $tasks
     * @param list<\App\Application\Proxmox\Pbs\PbsTaskInspection> $inspections
     */
    private function pbsCommit(
        InventoryIdentifier $run,
        MonitoringRunKind $kind,
        array $scopes,
        array $jobs = [],
        array $tasks = [],
        array $inspections = [],
    ): MonitoringCommit {
        return new MonitoringCommit(
            $run,
            $this->parentRunId,
            $this->connectionId,
            $this->endpointId,
            ProxmoxProduct::Pbs,
            $this->binding,
            $kind,
            1,
            $scopes,
            [],
            [],
            $jobs,
            $tasks,
            new DateTimeImmutable('2026-07-11T21:00:00Z'),
            $inspections,
        );
    }

    private function pbsJob(
        bool $disabled,
        string $schedule,
        ?PbsUpid $lastRunUpid = null,
        ?PbsTaskOutcome $lastRunOutcome = null,
        ?int $lastRunEndTime = null,
    ): PbsJobObservation
    {
        return new PbsJobObservation(
            PbsJobKind::Prune,
            new PbsJobId('prune_a'),
            new PbsDatastoreId('store_a'),
            null,
            $schedule,
            $disabled,
            null,
            null,
            null,
            null,
            $lastRunUpid,
            $lastRunOutcome,
            $lastRunEndTime,
            null,
        );
    }

    private function pbsJobUpid(string $startTimeHex): PbsUpid
    {
        return new PbsUpid(sprintf(
            'UPID:pbs-a:0000002A:000F4240:AAAAAAAAAAAAAAAA:%s:prunejob:prune_a:root@pam:',
            $startTimeHex,
        ));
    }

    private function pbsTask(
        bool $seenRunning,
        bool $seenHistory,
        ?string $reportedNode,
        ?PbsTaskOutcome $outcome,
        ?int $endTime,
    ): PbsTaskObservation {
        return new PbsTaskObservation(
            new PbsUpid('UPID:pbs-a:0000002A:000F4240:FFFFFFFFFFFFFFFF:68D927C0:backup:store_a:root@pam:'),
            $reportedNode,
            $seenRunning,
            $seenHistory,
            $outcome,
            $endTime,
        );
    }

    private function pbsJobScope(PbsJobKind $kind): MonitoringScopeResult
    {
        return new MonitoringScopeResult(
            match ($kind) {
                PbsJobKind::Prune => MonitoringScopeType::PbsPruneJobs,
                PbsJobKind::Sync => MonitoringScopeType::PbsSyncJobs,
                PbsJobKind::Verify => MonitoringScopeType::PbsVerifyJobs,
            },
            '@installation',
            MonitoringSourceKind::Jobs,
            $kind->value,
            MonitoringScopeStatus::Complete,
            null,
            null,
            1,
            1,
            1,
            false,
            false,
            null,
            new DateTimeImmutable('2026-07-11T21:00:00Z'),
        );
    }

    private function pbsTaskScope(PbsTaskFilterFamily $family, bool $history): MonitoringScopeResult
    {
        return new MonitoringScopeResult(
            $history ? MonitoringScopeType::PbsTasksWindow : MonitoringScopeType::PbsTasksRunning,
            'pbs-a',
            $history ? MonitoringSourceKind::History : MonitoringSourceKind::Running,
            $family->value,
            MonitoringScopeStatus::Complete,
            $history ? new DateTimeImmutable('2026-07-11T20:00:00Z') : null,
            $history ? new DateTimeImmutable('2026-07-11T21:00:00Z') : null,
            1,
            1,
            1,
            false,
            false,
            null,
            new DateTimeImmutable('2026-07-11T21:00:00Z'),
        );
    }

    /** @return list<MonitoringScopeResult> */
    private function pbsHistoryScopes(
        string $since = '2026-07-11T20:00:00Z',
        string $until = '2026-07-11T21:00:00Z',
        ?PbsTaskFilterFamily $incomplete = null,
        bool $failed = false,
        ?PbsTaskFilterFamily $mismatched = null,
    ): array {
        $scopes = [];
        foreach (PbsTaskFilterFamily::cases() as $family) {
            $status = $family === $incomplete
                ? ($failed ? MonitoringScopeStatus::Failed : MonitoringScopeStatus::Partial)
                : MonitoringScopeStatus::Complete;
            $scopeUntil = $family === $mismatched
                ? (new DateTimeImmutable($until))->modify('+1 second')
                : new DateTimeImmutable($until);
            $scopes[] = new MonitoringScopeResult(
                MonitoringScopeType::PbsTasksWindow,
                'pbs-a',
                MonitoringSourceKind::History,
                $family->value,
                $status,
                new DateTimeImmutable($since),
                $scopeUntil,
                1,
                1,
                0,
                MonitoringScopeStatus::Partial === $status,
                false,
                MonitoringScopeStatus::Complete === $status ? null : 'read_failed',
                new DateTimeImmutable('2026-07-11T22:00:00Z'),
            );
        }
        return $scopes;
    }

    private function switchToPbs(): void
    {
        $connection = $this->connection();
        $connection->delete('proxmox_installation_bindings', [
            'connection_id' => $this->connectionId->binary(),
        ]);
        $connection->update('proxmox_connections', ['product' => 'pbs'], [
            'id' => $this->connectionId->binary(),
        ]);
        $connection->update('proxmox_connection_endpoints', [
            'host' => 'pbs-a.invalid',
            'port' => 8007,
        ], ['id' => $this->endpointId->bytes]);
        $this->binding = InstallationBinding::pbsLegacyEndpoint($this->endpointId);
        $connection->insert('proxmox_installation_bindings', [
            'connection_id' => $this->connectionId->binary(),
            'product' => 'pbs',
            'identity_kind' => 'pbs_legacy_node',
            'identity_value' => 'localhost',
            'legacy_endpoint_id' => $this->endpointId->bytes,
            'first_bound_run_id' => $this->parentRunId->binary(),
            'last_verified_run_id' => $this->parentRunId->binary(),
            'first_bound_at' => '2026-07-11 20:00:01.000000',
            'last_verified_at' => '2026-07-11 20:00:01.000000',
        ]);
        $connection->insert('pbs_servers', [
            'id' => self::bytes('pbs-server'),
            'connection_id' => $this->connectionId->binary(),
            'node_name' => 'pbs-a',
            'version_major' => 4,
            'version_minor' => 2,
            'version_patch' => 0,
            'version_text' => '4.2.0',
            'release_text' => '1',
            'repo_id' => 'abcdef12',
            'first_seen_run_id' => $this->parentRunId->binary(),
            'last_seen_run_id' => $this->parentRunId->binary(),
            'first_seen_at' => '2026-07-11 20:00:01.000000',
            'last_seen_at' => '2026-07-11 20:00:01.000000',
        ]);
    }

    /**
     * @param list<MonitoringScopeResult> $scopes
     * @param list<PveBackupTask>         $tasks
     */
    private function commit(
        InventoryIdentifier $run,
        MonitoringRunKind $kind,
        array $scopes,
        array $tasks = [],
    ): MonitoringCommit {
        return new MonitoringCommit(
            $run,
            $this->parentRunId,
            $this->connectionId,
            $this->endpointId,
            ProxmoxProduct::Pve,
            $this->binding,
            $kind,
            1,
            $scopes,
            [],
            $tasks,
            [],
            [],
            new DateTimeImmutable('2026-07-11T21:00:00Z'),
        );
    }

    private function jobsScope(MonitoringScopeStatus $status): MonitoringScopeResult
    {
        return new MonitoringScopeResult(
            MonitoringScopeType::PveBackupJobs,
            '@installation',
            MonitoringSourceKind::Jobs,
            null,
            $status,
            null,
            null,
            1,
            0,
            0,
            false,
            false,
            MonitoringScopeStatus::Complete === $status ? null : 'read_failed',
            new DateTimeImmutable('2026-07-11T21:00:00Z'),
        );
    }

    private function archiveScope(
        MonitoringScopeStatus $status,
        bool $historyGap = false,
    ): MonitoringScopeResult {
        return new MonitoringScopeResult(
            MonitoringScopeType::PveTasksArchive,
            'pve-a',
            MonitoringSourceKind::Archive,
            'vzdump',
            $status,
            new DateTimeImmutable('2026-07-11T20:00:00Z'),
            new DateTimeImmutable('2026-07-11T21:00:00Z'),
            1,
            1,
            1,
            MonitoringScopeStatus::Partial === $status,
            $historyGap,
            MonitoringScopeStatus::Complete === $status ? null : 'read_failed',
            new DateTimeImmutable('2026-07-11T21:00:00Z'),
        );
    }

    private static function task(PveTaskSource $source, ?int $endTime, ?string $status): PveBackupTask
    {
        return new PveBackupTask(
            PveUpid::parse('UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:observer@pve:'),
            $source,
            $endTime,
            $status,
        );
    }

    private function seedUsableParent(): void
    {
        $connection = $this->connection();
        $connection->insert('proxmox_connections', [
            'id' => $this->connectionId->binary(),
            'display_name' => 'PVE monitoring test',
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => '2026-07-11 19:00:00.000000',
            'updated_at' => '2026-07-11 19:00:00.000000',
        ]);
        $connection->insert('proxmox_connection_endpoints', [
            'id' => $this->endpointId->bytes,
            'connection_id' => $this->connectionId->binary(),
            'host' => 'pve-a.invalid',
            'port' => 8006,
            'priority' => 1,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'created_at' => '2026-07-11 19:00:00.000000',
            'updated_at' => '2026-07-11 19:00:00.000000',
        ]);
        $connection->insert('collector_schedule', [
            'schedule_name' => 'inventory',
            'grid_started_at' => '2026-07-11 19:00:00.000000',
            'interval_seconds' => 120,
            'next_scan_at' => '2026-07-11 20:02:00.000000',
            'lease_owner' => $this->lease->ownerId->bytes,
            'lease_token' => $this->lease->token->binary(),
            'lease_fencing_token' => $this->lease->fencingToken,
            'lease_acquired_at' => '2026-07-11 19:59:59.000000',
            'lease_expires_at' => '2099-01-01 00:00:00.000000',
            'last_cycle_started_at' => '2026-07-11 20:00:00.000000',
            'updated_at' => '2026-07-11 20:00:00.000000',
        ]);
        $connection->insert('worker_heartbeats', [
            'worker_instance_id' => $this->lease->ownerId->bytes,
            'worker_kind' => 'collector',
            'status' => 'busy',
            'started_at' => '2026-07-11 19:00:00.000000',
            'heartbeat_at' => '2026-07-11 20:00:00.000000',
            'expires_at' => '2099-01-01 00:00:00.000000',
            'current_activity' => 'inventory',
            'current_cycle_token' => $this->lease->token->binary(),
            'build_version' => 'test',
        ]);
        $connection->insert('collector_cycles', [
            'cycle_token' => $this->lease->token->binary(),
            'schedule_name' => 'inventory',
            'worker_instance_id' => $this->lease->ownerId->bytes,
            'worker_kind' => 'collector',
            'fencing_token' => $this->lease->fencingToken,
            'scheduled_for' => '2026-07-11 20:00:00.000000',
            'started_at' => '2026-07-11 20:00:00.000000',
            'heartbeat_at' => '2026-07-11 20:00:00.000000',
            'status' => 'running',
        ]);
        $connection->insert('inventory_sync_runs', [
            'id' => $this->parentRunId->binary(),
            'cycle_token' => $this->lease->token->binary(),
            'collector_fencing_token' => $this->lease->fencingToken,
            'connection_id' => $this->connectionId->binary(),
            'expected_connection_revision' => 1,
            'endpoint_id' => $this->endpointId->bytes,
            'status' => 'succeeded',
            'authoritative' => 1,
            'started_at' => '2026-07-11 20:00:00.000000',
            'heartbeat_at' => '2026-07-11 20:00:01.000000',
            'finished_at' => '2026-07-11 20:00:01.000000',
            'applied_at' => '2026-07-11 20:00:01.000000',
        ]);
        $connection->insert('inventory_sync_endpoint_attempts', [
            'id' => self::bytes('attempt'),
            'connection_id' => $this->connectionId->binary(),
            'sync_run_id' => $this->parentRunId->binary(),
            'endpoint_id' => $this->endpointId->bytes,
            'attempt_number' => 1,
            'outcome' => 'selected',
            'started_at' => '2026-07-11 20:00:00.000000',
            'finished_at' => '2026-07-11 20:00:00.500000',
        ]);
        $connection->insert('proxmox_installation_bindings', [
            'connection_id' => $this->connectionId->binary(),
            'product' => 'pve',
            'identity_kind' => 'pve_standalone',
            'identity_value' => 'pve-a',
            'first_bound_run_id' => $this->parentRunId->binary(),
            'last_verified_run_id' => $this->parentRunId->binary(),
            'first_bound_at' => '2026-07-11 20:00:01.000000',
            'last_verified_at' => '2026-07-11 20:00:01.000000',
        ]);
    }

    private function errorCode(InventoryIdentifier $run): string
    {
        $value = $this->connection()->fetchOne(
            'SELECT error_code FROM proxmox_monitoring_runs WHERE id = :id',
            ['id' => $run->binary()],
        );
        self::assertIsString($value);
        return $value;
    }

    /**
     * @param array<int<0, max>|string, mixed> $parameters
     * @return int<0, max>
     */
    private function rowCount(string $sql, array $parameters = []): int
    {
        $value = $this->connection()->fetchOne($sql, $parameters);
        if (!is_int($value) || $value < 0) {
            self::fail('MariaDB returned an invalid row count.');
        }
        return $value;
    }

    private function rotateParent(string $seed): void
    {
        $connection = $this->connection();
        $connection->update('collector_cycles', [
            'status' => 'succeeded',
            'heartbeat_at' => '2026-07-11 21:00:00.000000',
            'finished_at' => '2026-07-11 21:00:00.000000',
            'duration_ms' => 1,
        ], ['cycle_token' => $this->lease->token->binary(), 'status' => 'running']);

        $fence = $this->lease->fencingToken + 1;
        $token = new CollectorCycleToken(self::bytes($seed.'-cycle'));
        $this->lease = new CollectorLease(
            $this->lease->ownerId,
            $token,
            $fence,
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
        );
        $this->parentRunId = self::id($seed.'-run');
        $connection->update('collector_schedule', [
            'lease_token' => $token->binary(),
            'lease_fencing_token' => $fence,
            'lease_acquired_at' => '2026-07-11 21:00:00.000000',
            'lease_expires_at' => '2099-01-01 00:00:00.000000',
            'last_cycle_started_at' => '2026-07-11 21:00:00.000000',
            'updated_at' => '2026-07-11 21:00:00.000000',
        ], ['schedule_name' => 'inventory']);
        $connection->update('worker_heartbeats', [
            'heartbeat_at' => '2026-07-11 21:00:00.000000',
            'current_cycle_token' => $token->binary(),
        ], ['worker_instance_id' => $this->lease->ownerId->bytes]);
        $connection->insert('collector_cycles', [
            'cycle_token' => $token->binary(),
            'schedule_name' => 'inventory',
            'worker_instance_id' => $this->lease->ownerId->bytes,
            'worker_kind' => 'collector',
            'fencing_token' => $fence,
            'scheduled_for' => '2026-07-11 21:00:00.000000',
            'started_at' => '2026-07-11 21:00:00.000000',
            'heartbeat_at' => '2026-07-11 21:00:00.000000',
            'status' => 'running',
        ]);
        $connection->insert('inventory_sync_runs', [
            'id' => $this->parentRunId->binary(),
            'cycle_token' => $token->binary(),
            'collector_fencing_token' => $fence,
            'connection_id' => $this->connectionId->binary(),
            'expected_connection_revision' => 1,
            'endpoint_id' => $this->endpointId->bytes,
            'status' => 'succeeded',
            'authoritative' => 1,
            'started_at' => '2026-07-11 21:00:00.000000',
            'heartbeat_at' => '2026-07-11 21:00:01.000000',
            'finished_at' => '2026-07-11 21:00:01.000000',
            'applied_at' => '2026-07-11 21:00:01.000000',
        ]);
        $connection->insert('inventory_sync_endpoint_attempts', [
            'id' => self::bytes($seed.'-attempt'),
            'connection_id' => $this->connectionId->binary(),
            'sync_run_id' => $this->parentRunId->binary(),
            'endpoint_id' => $this->endpointId->bytes,
            'attempt_number' => 1,
            'outcome' => 'selected',
            'started_at' => '2026-07-11 21:00:00.000000',
            'finished_at' => '2026-07-11 21:00:00.500000',
        ]);
        $connection->update('proxmox_installation_bindings', [
            'last_verified_run_id' => $this->parentRunId->binary(),
            'last_verified_at' => '2026-07-11 21:00:01.000000',
        ], ['connection_id' => $this->connectionId->binary()]);
    }

    private static function id(string $seed): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes($seed));
    }

    private static function endpoint(string $seed): EndpointId
    {
        return new EndpointId(self::bytes($seed));
    }

    private static function bytes(string $seed): string
    {
        return substr(hash('sha256', $seed, true), 0, 16);
    }
}

final class MonitoringSequentialIdentifierGenerator implements InventoryIdentifierGenerator
{
    private int $next = 0;

    public function generate(): InventoryIdentifier
    {
        return new InventoryIdentifier(substr(hash('sha256', 'monitoring-'.$this->next++, true), 0, 16));
    }
}
