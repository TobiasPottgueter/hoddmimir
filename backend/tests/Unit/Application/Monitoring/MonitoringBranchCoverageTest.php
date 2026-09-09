<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Monitoring;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Monitoring\MonitoringCommit;
use App\Application\Monitoring\MonitoringCursorCatalog;
use App\Application\Monitoring\MonitoringCursorKind;
use App\Application\Monitoring\MonitoringRunFailure;
use App\Application\Monitoring\MonitoringRunKind;
use App\Application\Monitoring\MonitoringRunStatus;
use App\Application\Monitoring\MonitoringScopeResult;
use App\Application\Monitoring\MonitoringScopeStatus;
use App\Application\Monitoring\MonitoringScopeType;
use App\Application\Monitoring\MonitoringSourceKind;
use App\Application\Monitoring\MonitoringWindowPlan;
use App\Application\Monitoring\MonitoringWindowPlanner;
use App\Application\Monitoring\PbsExternalMonitoringSnapshot;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsJobId;
use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsJobListSnapshot;
use App\Application\Proxmox\Pbs\PbsJobObservation;
use App\Application\Proxmox\Pbs\PbsTaskObservation;
use App\Application\Proxmox\Pbs\PbsTaskOutcome;
use App\Application\Proxmox\Pbs\PbsUpid as PbsUpid;
use App\Application\Proxmox\Pve\PveBackupJob;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveUpid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MonitoringBranchCoverageTest extends TestCase
{
    public function testEveryScopeContractAcceptsItsExactShapeAndRejectsWrongSourceFilterOrWindow(): void
    {
        foreach (MonitoringScopeType::cases() as $type) {
            $valid = $this->scope($type);
            self::assertSame($type, $valid->scope);

            $this->assertInvalid(fn () => new MonitoringScopeResult(
                $type,
                'scope',
                MonitoringSourceKind::Jobs,
                'invalid',
                MonitoringScopeStatus::Complete,
                null,
                null,
                0,
                0,
                0,
                false,
                false,
                null,
                $this->now(),
            ));
        }

        $this->assertInvalid(fn () => new MonitoringScopeResult(
            MonitoringScopeType::PbsTasksWindow,
            'pbs-a',
            MonitoringSourceKind::History,
            null,
            MonitoringScopeStatus::Complete,
            $this->at(1),
            $this->at(2),
            0,
            0,
            0,
            false,
            false,
            null,
            $this->now(),
        ));
        $this->assertInvalid(fn () => new MonitoringScopeResult(
            MonitoringScopeType::PbsTasksWindow,
            'pbs-a',
            MonitoringSourceKind::History,
            'backup',
            MonitoringScopeStatus::Complete,
            null,
            null,
            0,
            0,
            0,
            false,
            false,
            null,
            $this->now(),
        ));
        $this->assertInvalid(fn () => new MonitoringScopeResult(
            MonitoringScopeType::PbsTasksWindow,
            'pbs-a',
            MonitoringSourceKind::History,
            'unknown',
            MonitoringScopeStatus::Complete,
            $this->at(1),
            $this->at(2),
            0,
            0,
            0,
            false,
            false,
            null,
            $this->now(),
        ));
    }

    public function testScopeFailureValidationCoversLongErrorAndValidHistoryGap(): void
    {
        $gap = new MonitoringScopeResult(
            MonitoringScopeType::PbsTasksWindow,
            'pbs-a',
            MonitoringSourceKind::History,
            'backup',
            MonitoringScopeStatus::Partial,
            $this->at(1),
            $this->at(2),
            0,
            0,
            0,
            true,
            true,
            'history_gap',
            $this->now(),
        );
        self::assertTrue($gap->historyGap);
        $this->assertInvalid(fn () => new MonitoringScopeResult(
            MonitoringScopeType::PveTasksArchive,
            'pve-a',
            MonitoringSourceKind::Archive,
            'vzdump',
            MonitoringScopeStatus::Partial,
            $this->at(1),
            $this->at(2),
            0,
            0,
            0,
            false,
            false,
            str_repeat('x', 65),
            $this->now(),
        ));
        $this->assertInvalid(fn () => new MonitoringScopeResult(
            MonitoringScopeType::PveTasksArchive,
            'pve-a',
            MonitoringSourceKind::Archive,
            'vzdump',
            MonitoringScopeStatus::Partial,
            $this->at(1),
            $this->at(2),
            0,
            0,
            0,
            false,
            false,
            '',
            $this->now(),
        ));
    }

    public function testCommitCountersPayloadVariantsSortingAndDuplicateGuards(): void
    {
        $pveJob = $this->pveJob();
        $pveTask = $this->pveTask();
        $pbsJob = $this->pbsJob();
        $pbsTask = $this->pbsTask();

        $variants = [
            $this->commit(ProxmoxProduct::Pve, MonitoringRunKind::ExternalJobs, [$this->scope(MonitoringScopeType::PveBackupJobs)], [$pveJob]),
            $this->commit(ProxmoxProduct::Pve, MonitoringRunKind::ObservedTasks, [$this->scope(MonitoringScopeType::PveTasksActive)], [], [$pveTask]),
            $this->commit(ProxmoxProduct::Pbs, MonitoringRunKind::ExternalJobs, [$this->scope(MonitoringScopeType::PbsPruneJobs)], [], [], [$pbsJob]),
            $this->commit(ProxmoxProduct::Pbs, MonitoringRunKind::ObservedTasks, [$this->scope(MonitoringScopeType::PbsTasksRunning)], [], [], [], [$pbsTask]),
        ];
        foreach ($variants as $commit) {
            self::assertSame(1, $commit->pagesRead());
            self::assertSame(2, $commit->rowsRead());
            self::assertSame(1, $commit->itemsSeen());
            self::assertSame(MonitoringRunStatus::Succeeded, $commit->status());
        }

        $failed = $this->commit(
            ProxmoxProduct::Pve,
            MonitoringRunKind::ObservedTasks,
            [$this->scope(MonitoringScopeType::PveTasksActive, MonitoringScopeStatus::Failed)],
        );
        self::assertSame(MonitoringRunStatus::Failed, $failed->status());

        foreach ([
            fn () => $this->commit(ProxmoxProduct::Pve, MonitoringRunKind::ExternalJobs, [$this->scope(MonitoringScopeType::PveBackupJobs)], [$pveJob, $pveJob]),
            fn () => $this->commit(ProxmoxProduct::Pve, MonitoringRunKind::ObservedTasks, [$this->scope(MonitoringScopeType::PveTasksActive)], [], [$pveTask, $pveTask]),
            fn () => $this->commit(ProxmoxProduct::Pbs, MonitoringRunKind::ExternalJobs, [$this->scope(MonitoringScopeType::PbsPruneJobs)], [], [], [$pbsJob, $pbsJob]),
            fn () => $this->commit(ProxmoxProduct::Pbs, MonitoringRunKind::ObservedTasks, [$this->scope(MonitoringScopeType::PbsTasksRunning)], [], [], [], [$pbsTask, $pbsTask]),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }
    }

    public function testInspectionsMustReferToUniqueObservedTasks(): void
    {
        $task = $this->pbsTask();
        $inspection = new \App\Application\Proxmox\Pbs\PbsTaskInspection($task->upid, 'running', null, null, [], false, null, null);
        self::assertSame([$inspection], $this->inspectionCommit([$task], [$inspection])->pbsInspections);
        $this->assertInvalid(fn () => $this->inspectionCommit([], [$inspection]));
        $this->assertInvalid(fn () => $this->inspectionCommit([$task], [$inspection, $inspection]));
    }

    /**
     * @param list<PbsTaskObservation> $tasks
     * @param list<\App\Application\Proxmox\Pbs\PbsTaskInspection> $inspections
     */
    private function inspectionCommit(array $tasks, array $inspections): MonitoringCommit
    {
        return $this->rawCommit(ProxmoxProduct::Pbs, InstallationBinding::pbsLegacyEndpoint($this->endpoint()),
            MonitoringRunKind::ObservedTasks, 1, [$this->scope(MonitoringScopeType::PbsTasksRunning)],
            pbsTasks: $tasks, inspections: $inspections);
    }

    public function testCommitRejectsEveryHeaderAndPayloadMismatch(): void
    {
        $pveScope = $this->scope(MonitoringScopeType::PveBackupJobs);
        $pbsScope = $this->scope(MonitoringScopeType::PbsPruneJobs);
        foreach ([
            fn () => $this->rawCommit(ProxmoxProduct::Pve, InstallationBinding::pveStandalone('pve-a'), MonitoringRunKind::ExternalJobs, 0, [$pveScope]),
            fn () => $this->rawCommit(ProxmoxProduct::Pve, InstallationBinding::pbsInstance(str_repeat('a', 32)), MonitoringRunKind::ExternalJobs, 1, [$pveScope]),
            fn () => $this->rawCommit(ProxmoxProduct::Pve, InstallationBinding::pveStandalone('pve-a'), MonitoringRunKind::ExternalJobs, 1, []),
            fn () => $this->commit(ProxmoxProduct::Pve, MonitoringRunKind::ExternalJobs, [$pveScope], [], [$this->pveTask()]),
            fn () => $this->commit(ProxmoxProduct::Pve, MonitoringRunKind::ObservedTasks, [$this->scope(MonitoringScopeType::PveTasksActive)], [$this->pveJob()]),
            fn () => $this->commit(ProxmoxProduct::Pve, MonitoringRunKind::ExternalJobs, [$pveScope], pbsJobs: [$this->pbsJob()]),
            fn () => $this->commit(ProxmoxProduct::Pve, MonitoringRunKind::ExternalJobs, [$pveScope], pbsTasks: [$this->pbsTask()]),
            fn () => $this->commit(ProxmoxProduct::Pbs, MonitoringRunKind::ExternalJobs, [$pbsScope], [$this->pveJob()]),
            fn () => $this->commit(ProxmoxProduct::Pbs, MonitoringRunKind::ExternalJobs, [$pbsScope], pveTasks: [$this->pveTask()]),
            fn () => $this->commit(ProxmoxProduct::Pbs, MonitoringRunKind::ExternalJobs, [$pbsScope], pbsTasks: [$this->pbsTask()]),
            fn () => $this->commit(ProxmoxProduct::Pbs, MonitoringRunKind::ObservedTasks, [$this->scope(MonitoringScopeType::PbsTasksRunning)], pbsJobs: [$this->pbsJob()]),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }
    }

    public function testRuntimeArrayBoundariesRejectUntypedScopeAndPayloadValues(): void
    {
        $cases = [
            [[new \stdClass()], [], [], [], []],
            [[$this->scope(MonitoringScopeType::PveBackupJobs)], [new \stdClass()], [], [], []],
            [[$this->scope(MonitoringScopeType::PveTasksActive)], [], [new \stdClass()], [], []],
            [[$this->scope(MonitoringScopeType::PbsPruneJobs)], [], [], [new \stdClass()], []],
            [[$this->scope(MonitoringScopeType::PbsTasksRunning)], [], [], [], [new \stdClass()]],
        ];
        foreach ($cases as $index => [$scopes, $pveJobs, $pveTasks, $pbsJobs, $pbsTasks]) {
            $product = $index < 3 ? ProxmoxProduct::Pve : ProxmoxProduct::Pbs;
            $kind = in_array($index, [0, 1, 3], true)
                ? MonitoringRunKind::ExternalJobs : MonitoringRunKind::ObservedTasks;
            $binding = ProxmoxProduct::Pve === $product
                ? InstallationBinding::pveStandalone('pve-a')
                : InstallationBinding::pbsLegacyEndpoint($this->endpoint());
            try {
                // @phpstan-ignore-next-line runtime boundary is intentionally exercised
                $this->rawCommit($product, $binding, $kind, 1, $scopes, $pveJobs, $pveTasks, $pbsJobs, $pbsTasks);
                self::fail('An untyped monitoring payload value was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWindowValuePlannerAndFailureBoundaries(): void
    {
        $plan = new MonitoringWindowPlan(1, 2, false);
        self::assertSame(1, $plan->pve()->since);
        self::assertSame(2, $plan->pbs()->until);
        foreach ([[-1, 1], [2, 1]] as [$since, $until]) {
            $this->assertInvalid(fn () => new MonitoringWindowPlan($since, $until, false));
        }
        foreach ([-1, 3601] as $overlap) {
            $this->assertInvalid(fn () => new MonitoringWindowPlanner(new BranchMonitoringCursor(null), $overlap));
        }
        $planner = new MonitoringWindowPlanner(new BranchMonitoringCursor(null), 0);
        foreach ([0, 86_401] as $maximum) {
            $this->assertInvalid(fn () => $planner->plan(
                new ConnectionId(self::bytes('connection')),
                MonitoringCursorKind::PveTasksArchive,
                ['pve-a'],
                $this->at(10),
                $maximum,
            ));
        }
        $this->assertInvalid(fn () => $planner->plan(
            new ConnectionId(self::bytes('connection')),
            MonitoringCursorKind::PveTasksArchive,
            ['pve-a'],
            new DateTimeImmutable('@-1'),
            10,
        ));
        self::assertSame(0, $planner->plan(
            new ConnectionId(self::bytes('connection')),
            MonitoringCursorKind::PveTasksArchive,
            ['pve-a'],
            $this->at(5),
            10,
        )->since);
        self::assertSame(800, (new MonitoringWindowPlanner(
            new BranchMonitoringCursor($this->at(1100)),
            300,
        ))->plan(
            new ConnectionId(self::bytes('connection')),
            MonitoringCursorKind::PveTasksArchive,
            ['pve-a'],
            $this->at(1000),
            600,
        )->since);

        $this->assertInvalid(fn () => new MonitoringRunFailure(
            $this->id('failure'),
            $this->id('connection'),
            str_repeat('x', 65),
            $this->now(),
        ));
        $this->assertInvalid(fn () => new MonitoringRunFailure(
            $this->id('failure-empty'),
            $this->id('connection'),
            '',
            $this->now(),
        ));
    }

    public function testPbsSnapshotRejectsMalformedErrorsKindsAndPresenceMismatch(): void
    {
        $prune = new PbsJobListSnapshot(PbsJobKind::Prune, str_repeat('a', 64), []);
        $sync = new PbsJobListSnapshot(PbsJobKind::Sync, str_repeat('b', 64), []);
        $verify = new PbsJobListSnapshot(PbsJobKind::Verify, str_repeat('c', 64), []);
        foreach ([
            fn () => new PbsExternalMonitoringSnapshot(null, null, null, null, null, ['bad' => 'read_failed']),
            fn () => new PbsExternalMonitoringSnapshot(null, null, null, null, null, ['acl' => '']),
            fn () => new PbsExternalMonitoringSnapshot(null, null, null, null, null, ['acl' => str_repeat('x', 65)]),
            fn () => new PbsExternalMonitoringSnapshot(null, null, null, null, null, ['acl' => 'Bad Error']),
            fn () => new PbsExternalMonitoringSnapshot(null, $sync, null, null, null, ['acl' => 'x', 'sync' => 'x', 'verify' => 'x', 'tasks' => 'x']),
            fn () => new PbsExternalMonitoringSnapshot(null, null, $prune, null, null, ['acl' => 'x', 'prune' => 'x', 'verify' => 'x', 'tasks' => 'x']),
            fn () => new PbsExternalMonitoringSnapshot(null, null, null, $sync, null, ['acl' => 'x', 'prune' => 'x', 'sync' => 'x', 'tasks' => 'x']),
            fn () => new PbsExternalMonitoringSnapshot(null, $prune, $sync, $verify, null, ['acl' => 'x', 'prune' => 'x', 'tasks' => 'x']),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }
        $longCode = str_repeat('x', 64);
        $snapshot = new PbsExternalMonitoringSnapshot(null, null, null, null, null, [
            'acl' => $longCode,
            'prune' => $longCode,
            'sync' => $longCode,
            'verify' => $longCode,
            'tasks' => $longCode,
        ]);
        self::assertSame($longCode, $snapshot->errors['acl']);
    }

    public function testScopeBoundaryValuesRemainAccepted(): void
    {
        $scope = new MonitoringScopeResult(
            MonitoringScopeType::PveTasksArchive,
            str_repeat('k', 255),
            MonitoringSourceKind::Archive,
            'vzdump',
            MonitoringScopeStatus::Partial,
            $this->at(2),
            $this->at(2),
            0,
            0,
            0,
            false,
            false,
            str_repeat('e', 64),
            $this->now(),
        );

        self::assertSame(255, strlen($scope->key));
        self::assertSame('vzdump', $scope->filter);
        self::assertSame(64, strlen((string) $scope->errorCode));
        self::assertSame($scope->windowSince?->getTimestamp(), $scope->windowUntil?->getTimestamp());
    }

    public function testEachScopeContractRejectsOneWrongDimensionAtATime(): void
    {
        $cases = [
            [MonitoringScopeType::PveBackupJobs, MonitoringSourceKind::Active, null, null, null],
            [MonitoringScopeType::PveBackupJobs, MonitoringSourceKind::Jobs, 'vzdump', null, null],
            [MonitoringScopeType::PveBackupJobs, MonitoringSourceKind::Jobs, null, $this->at(1), $this->at(2)],
            [MonitoringScopeType::PveTasksActive, MonitoringSourceKind::Jobs, 'vzdump', null, null],
            [MonitoringScopeType::PveTasksActive, MonitoringSourceKind::Active, 'backup', null, null],
            [MonitoringScopeType::PveTasksActive, MonitoringSourceKind::Active, 'vzdump', $this->at(1), $this->at(2)],
            [MonitoringScopeType::PveTasksArchive, MonitoringSourceKind::Jobs, 'vzdump', $this->at(1), $this->at(2)],
            [MonitoringScopeType::PveTasksArchive, MonitoringSourceKind::Archive, 'backup', $this->at(1), $this->at(2)],
            [MonitoringScopeType::PveTasksArchive, MonitoringSourceKind::Archive, 'vzdump', null, null],
            [MonitoringScopeType::PbsTasksRunning, MonitoringSourceKind::Jobs, 'backup', null, null],
            [MonitoringScopeType::PbsTasksRunning, MonitoringSourceKind::Running, 'unknown', null, null],
            [MonitoringScopeType::PbsTasksRunning, MonitoringSourceKind::Running, 'backup', $this->at(1), $this->at(2)],
            [MonitoringScopeType::PbsTasksWindow, MonitoringSourceKind::Jobs, 'backup', $this->at(1), $this->at(2)],
            [MonitoringScopeType::PbsTasksWindow, MonitoringSourceKind::History, 'unknown', $this->at(1), $this->at(2)],
            [MonitoringScopeType::PbsTasksWindow, MonitoringSourceKind::History, 'backup', null, null],
        ];

        foreach ($cases as [$type, $source, $filter, $since, $until]) {
            $this->assertInvalid(fn () => new MonitoringScopeResult(
                $type,
                'scope',
                $source,
                $filter,
                MonitoringScopeStatus::Complete,
                $since,
                $until,
                0,
                0,
                0,
                false,
                false,
                null,
                $this->now(),
            ));
        }
    }

    public function testCommitCanonicalizesScopesAndEveryPayloadType(): void
    {
        $filters = ['verif', 'backup', 'syncjob', 'prune'];
        $scopes = array_map(
            fn (string $filter): MonitoringScopeResult => new MonitoringScopeResult(
                MonitoringScopeType::PbsTasksRunning,
                'pbs-a',
                MonitoringSourceKind::Running,
                $filter,
                MonitoringScopeStatus::Complete,
                null,
                null,
                1,
                2,
                3,
                false,
                false,
                null,
                $this->now(),
            ),
            $filters,
        );
        $scopeCommit = $this->commit(ProxmoxProduct::Pbs, MonitoringRunKind::ObservedTasks, $scopes);
        self::assertSame([0, 1, 2, 3], array_keys($scopeCommit->scopes));
        self::assertSame(['backup', 'prune', 'syncjob', 'verif'], array_column($scopeCommit->scopes, 'filter'));
        self::assertSame(4, $scopeCommit->pagesRead());
        self::assertSame(8, $scopeCommit->rowsRead());

        $pveJobs = $this->commit(
            ProxmoxProduct::Pve,
            MonitoringRunKind::ExternalJobs,
            [$this->scope(MonitoringScopeType::PveBackupJobs)],
            [$this->pveJob('job-z'), $this->pveJob('job-a')],
        )->pveJobs;
        self::assertSame([0, 1], array_keys($pveJobs));
        self::assertSame(['job-a', 'job-z'], array_column($pveJobs, 'id'));

        $pveTasks = $this->commit(
            ProxmoxProduct::Pve,
            MonitoringRunKind::ObservedTasks,
            [$this->scope(MonitoringScopeType::PveTasksActive)],
            pveTasks: [$this->pveTask('102'), $this->pveTask('101')],
        )->pveTasks;
        self::assertSame([0, 1], array_keys($pveTasks));
        self::assertSame(
            ['101', '102'],
            array_map(static fn (PveBackupTask $task): string => (string) $task->upid->id, $pveTasks),
        );

        $pbsJobs = $this->commit(
            ProxmoxProduct::Pbs,
            MonitoringRunKind::ExternalJobs,
            [$this->scope(MonitoringScopeType::PbsPruneJobs)],
            pbsJobs: [$this->pbsJob('job-z'), $this->pbsJob('job-a')],
        )->pbsJobs;
        self::assertSame([0, 1], array_keys($pbsJobs));
        self::assertSame(
            ['job-a', 'job-z'],
            array_map(static fn (PbsJobObservation $job): string => $job->id->value, $pbsJobs),
        );

        $pbsTasks = $this->commit(
            ProxmoxProduct::Pbs,
            MonitoringRunKind::ObservedTasks,
            [$this->scope(MonitoringScopeType::PbsTasksRunning)],
            pbsTasks: [$this->pbsTask('68D927C2'), $this->pbsTask('68D927C0')],
        )->pbsTasks;
        self::assertSame([0, 1], array_keys($pbsTasks));
        self::assertSame(
            ['68d927c0', '68d927c2'],
            array_map(static fn (PbsTaskObservation $task): string => $task->upid->startTimeHex, $pbsTasks),
        );
    }

    public function testWindowPlannerAcceptsExactBoundsAndPreservesBoundarySemantics(): void
    {
        $connection = new ConnectionId(self::bytes('connection'));
        $zero = (new MonitoringWindowPlanner(new BranchMonitoringCursor(null), 3600))->plan(
            $connection,
            MonitoringCursorKind::PveTasksArchive,
            ['pve-a'],
            $this->at(0),
            1,
        );
        self::assertSame([0, 0, false], [$zero->since, $zero->until, $zero->historyGap]);

        $exactHorizon = (new MonitoringWindowPlanner(
            new BranchMonitoringCursor($this->at(400)),
        ))->plan(
            $connection,
            MonitoringCursorKind::PveTasksArchive,
            ['pve-a'],
            $this->at(1000),
            600,
        );
        self::assertSame([400, 1000, false], [
            $exactHorizon->since,
            $exactHorizon->until,
            $exactHorizon->historyGap,
        ]);

        $fullMaximum = (new MonitoringWindowPlanner(new BranchMonitoringCursor(null)))->plan(
            $connection,
            MonitoringCursorKind::PveTasksArchive,
            ['pve-a'],
            $this->at(86_400),
            86_400,
        );
        self::assertSame([0, 86_400, false], [
            $fullMaximum->since,
            $fullMaximum->until,
            $fullMaximum->historyGap,
        ]);

        try {
            (new MonitoringWindowPlanner(new BranchMonitoringCursor(null), 0))->plan(
                $connection,
                MonitoringCursorKind::PveTasksArchive,
                ['pve-a'],
                new DateTimeImmutable('@-1'),
                1,
            );
            self::fail('A negative cutoff was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The monitoring cutoff cannot precede the UNIX epoch.', $exception->getMessage());
        }
    }

    public function testPbsSnapshotValidatesEveryErrorBeforePresenceAndSortsCanonicalKeys(): void
    {
        $valid = [
            'verify' => 'verify_failed',
            'tasks' => 'tasks_failed',
            'sync' => 'sync_failed',
            'prune' => 'prune_failed',
            'acl' => 'acl_failed',
        ];
        $snapshot = new PbsExternalMonitoringSnapshot(null, null, null, null, null, $valid);
        self::assertSame(['acl', 'prune', 'sync', 'tasks', 'verify'], array_keys($snapshot->errors));

        foreach (array_keys($valid) as $key) {
            $invalid = $valid;
            $invalid[$key] = 'Invalid';
            $this->assertInvalid(fn () => new PbsExternalMonitoringSnapshot(
                null,
                null,
                null,
                null,
                null,
                $invalid,
            ));
        }
    }

    private function scope(
        MonitoringScopeType $type,
        MonitoringScopeStatus $status = MonitoringScopeStatus::Complete,
    ): MonitoringScopeResult {
        [$source, $filter, $window] = match ($type) {
            MonitoringScopeType::PveBackupJobs => [MonitoringSourceKind::Jobs, null, false],
            MonitoringScopeType::PveTasksActive => [MonitoringSourceKind::Active, 'vzdump', false],
            MonitoringScopeType::PveTasksArchive => [MonitoringSourceKind::Archive, 'vzdump', true],
            MonitoringScopeType::PbsPruneJobs => [MonitoringSourceKind::Jobs, 'prune', false],
            MonitoringScopeType::PbsSyncJobs => [MonitoringSourceKind::Jobs, 'sync', false],
            MonitoringScopeType::PbsVerifyJobs => [MonitoringSourceKind::Jobs, 'verify', false],
            MonitoringScopeType::PbsTasksRunning => [MonitoringSourceKind::Running, 'backup', false],
            MonitoringScopeType::PbsTasksWindow => [MonitoringSourceKind::History, 'backup', true],
        };
        return new MonitoringScopeResult(
            $type,
            'scope',
            $source,
            $filter,
            $status,
            $window ? $this->at(1) : null,
            $window ? $this->at(2) : null,
            1,
            2,
            1,
            false,
            false,
            MonitoringScopeStatus::Complete === $status ? null : 'read_failed',
            $this->now(),
        );
    }

    /**
     * @param list<MonitoringScopeResult> $scopes
     * @param list<PveBackupJob> $pveJobs
     * @param list<PveBackupTask> $pveTasks
     * @param list<PbsJobObservation> $pbsJobs
     * @param list<PbsTaskObservation> $pbsTasks
     */
    private function commit(
        ProxmoxProduct $product,
        MonitoringRunKind $kind,
        array $scopes,
        array $pveJobs = [],
        array $pveTasks = [],
        array $pbsJobs = [],
        array $pbsTasks = [],
    ): MonitoringCommit {
        $binding = ProxmoxProduct::Pve === $product
            ? InstallationBinding::pveStandalone('pve-a')
            : InstallationBinding::pbsLegacyEndpoint($this->endpoint());
        return $this->rawCommit(
            $product, $binding, $kind, 1, $scopes, $pveJobs, $pveTasks, $pbsJobs, $pbsTasks,
        );
    }

    /**
     * @param list<MonitoringScopeResult> $scopes
     * @param list<PveBackupJob> $pveJobs
     * @param list<PveBackupTask> $pveTasks
     * @param list<PbsJobObservation> $pbsJobs
     * @param list<PbsTaskObservation> $pbsTasks
     * @param list<\App\Application\Proxmox\Pbs\PbsTaskInspection> $inspections
     */
    private function rawCommit(
        ProxmoxProduct $product,
        InstallationBinding $binding,
        MonitoringRunKind $kind,
        int $revision,
        array $scopes,
        array $pveJobs = [],
        array $pveTasks = [],
        array $pbsJobs = [],
        array $pbsTasks = [],
        array $inspections = [],
    ): MonitoringCommit {
        return new MonitoringCommit(
            $this->id('run'),
            $this->id('parent'),
            $this->id('connection'),
            $this->endpoint(),
            $product,
            $binding,
            $kind,
            $revision,
            $scopes,
            $pveJobs,
            $pveTasks,
            $pbsJobs,
            $pbsTasks,
            $this->now(),
            $inspections,
        );
    }

    private function pveJob(string $id = 'job-a'): PveBackupJob
    {
        return new PveBackupJob($id, null, null, null, null, null, null, null, null, null, null, null, null, null);
    }

    private function pveTask(string $id = '101'): PveBackupTask
    {
        return new PveBackupTask(
            PveUpid::parse('UPID:pve-a:0000002A:000F4240:67000000:vzdump:'.$id.':observer@pve:'),
            PveTaskSource::Active,
            null,
            'RUNNING',
        );
    }

    private function pbsJob(string $id = 'prune-a'): PbsJobObservation
    {
        return new PbsJobObservation(
            PbsJobKind::Prune,
            new PbsJobId($id),
            new PbsDatastoreId('store-a'),
            null,
            null,
            false,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }

    private function pbsTask(string $startTimeHex = '68D927C0'): PbsTaskObservation
    {
        return new PbsTaskObservation(
            new PbsUpid('UPID:pbs-a:0000002A:000F4240:FFFFFFFFFFFFFFFF:'.$startTimeHex.':backup:store-a:root@pam:'),
            'pbs-a',
            true,
            false,
            null,
            null,
        );
    }

    private function endpoint(): EndpointId
    {
        return new EndpointId(self::bytes('endpoint'));
    }

    private function id(string $seed): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes($seed));
    }

    private function at(int $epoch): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$epoch);
    }

    private function now(): DateTimeImmutable
    {
        return $this->at(200);
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            self::fail('An invalid monitoring value was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    private static function bytes(string $seed): string
    {
        return substr(hash('sha256', $seed, true), 0, 16);
    }
}

final readonly class BranchMonitoringCursor implements MonitoringCursorCatalog
{
    public function __construct(private ?DateTimeImmutable $value)
    {
    }

    public function oldestCompletedUntil(
        ConnectionId $connectionId,
        MonitoringCursorKind $kind,
        array $scopeKeys,
    ): ?DateTimeImmutable {
        return $this->value;
    }
}
