<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsAclEvidence;
use App\Application\Proxmox\Pbs\PbsAclIssueCode;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsJobId;
use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsJobListSnapshot;
use App\Application\Proxmox\Pbs\PbsJobObservation;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsSyncDirection;
use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsTaskListQuery;
use App\Application\Proxmox\Pbs\PbsTaskObservation;
use App\Application\Proxmox\Pbs\PbsTaskOutcome;
use App\Application\Proxmox\Pbs\PbsTaskPage;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Application\Proxmox\Pbs\PbsTaskScanIssue;
use App\Application\Proxmox\Pbs\PbsTaskScanIssueCode;
use App\Application\Proxmox\Pbs\PbsTaskScanSnapshot;
use App\Application\Proxmox\Pbs\PbsTaskStreamResult;
use App\Application\Proxmox\Pbs\PbsTaskStreamStatus;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;
use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Application\Proxmox\Pbs\PbsUpid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PbsTasksAndJobsModelTest extends TestCase
{
    public function testSafeIdentifiersNamespacesAndOutcomesAreTyped(): void
    {
        self::assertSame('job_a', (new PbsJobId('job_a'))->value);
        self::assertSame(str_repeat('a', 32), (new PbsJobId(str_repeat('a', 32)))->value);
        self::assertSame('a/b/c', (new PbsNamespace('a/b/c'))->value);
        self::assertSame(str_repeat('a', 256), (new PbsNamespace(str_repeat('a', 256)))->value);
        self::assertTrue(PbsNamespace::root()->isRoot());
        self::assertSame(PbsTaskOutcome::Ok, PbsTaskOutcome::fromRemoteStatus('OK'));
        self::assertSame(PbsTaskOutcome::Warning, PbsTaskOutcome::fromRemoteStatus('WARNINGS: 2'));
        self::assertSame(PbsTaskOutcome::Unknown, PbsTaskOutcome::fromRemoteStatus('Unknown'));
        self::assertSame(PbsTaskOutcome::Error, PbsTaskOutcome::fromRemoteStatus('Error: synthetic'));
        self::assertSame(PbsTaskOutcome::Error, PbsTaskOutcome::fromRemoteStatus(str_repeat('x', 255)));

        foreach (['ab', '-bad', 'abc!', str_repeat('a', 33)] as $id) {
            $this->assertInvalid(static fn () => new PbsJobId($id));
        }
        foreach (['bad//ns', 'bad!/ns', implode('/', array_fill(0, 9, 'x')), str_repeat('a', 257)] as $namespace) {
            $this->assertInvalid(static fn () => new PbsNamespace($namespace));
        }
        foreach (['', "bad\nstatus", str_repeat('x', 1025)] as $status) {
            $this->assertInvalid(static fn () => PbsTaskOutcome::fromRemoteStatus($status));
        }
    }

    public function testPhpTypeBoundariesRejectUntypedRuntimeCalls(): void
    {
        $cases = [
            [PbsJobId::class, [[]]],
            [PbsNamespace::class, [[]]],
            [PbsJobListSnapshot::class, [1, str_repeat('a', 64), []]],
            [PbsJobObservation::class, [
                1, new PbsJobId('job_a'), new PbsDatastoreId('store_a'), null, null, false,
                null, null, null, null, null, null, null, null,
            ]],
            [PbsTaskObservation::class, [1, null, true, false, null, null]],
            [PbsTaskPage::class, [1, null]],
            [PbsTaskPage::class, [[], 'bad']],
            [PbsTaskScanSnapshot::class, [1, [], []]],
            [PbsAclEvidence::class, [1, 1, 1]],
        ];
        foreach ($cases as [$class, $arguments]) {
            try {
                (new \ReflectionClass($class))->newInstanceArgs($arguments);
                self::fail('An untyped PBS constructor call was accepted.');
            } catch (\TypeError) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([
            [new \ReflectionMethod(PbsTaskFilterFamily::class, 'allows'), PbsTaskFilterFamily::Backup, [[]]],
            [new \ReflectionMethod(PbsTaskOutcome::class, 'fromRemoteStatus'), null, [[]]],
        ] as [$method, $object, $arguments]) {
            try {
                $method->invokeArgs($object, $arguments);
                self::fail('An untyped PBS method call was accepted.');
            } catch (\TypeError) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUpidParsesCanonicalFieldsAndRejectsUnsafeGrammar(): void
    {
        $upid = $this->upid('backup', 'store_a', 'backup@pbs!token', '100000000');
        self::assertSame('pbs-four', $upid->node);
        self::assertSame('0000002a', $upid->pidHex);
        self::assertSame('100000000', $upid->processStartHex);
        self::assertSame('ffffffffffffffff', $upid->taskIdHex);
        self::assertSame(42, $upid->pid);
        self::assertSame(4_294_967_296, $upid->processStart);
        self::assertSame(1_759_061_952, $upid->startTime);
        self::assertSame('store_a', $upid->workerId);

        $withoutWorker = $this->upid('backup', '', 'root@pam');
        self::assertNull($withoutWorker->workerId);
        foreach ([
            'not-upid',
            str_replace('root@pam:', "root @pam:", $withoutWorker->value),
            substr($withoutWorker->value, 0, -1),
            str_replace(':backup::', ':bad:type::', $withoutWorker->value),
            'UPID:'.str_repeat('n', 64).':0000002A:000F4240:FFFFFFFFFFFFFFFF:68D927C0:backup::root@pam:',
            'UPID:pbs:0000002A:000F4240:FFFFFFFFFFFFFFFF:68D927C0:'.str_repeat('t', 256).'::root@pam:',
            'UPID:pbs:0000002A:000F4240:FFFFFFFFFFFFFFFF:68D927C0:backup:'.str_repeat('i', 1025).':root@pam:',
            'UPID:pbs:0000002A:000F4240:FFFFFFFFFFFFFFFF:68D927C0:backup::'.str_repeat('a', 256).':',
            str_repeat('x', 2049),
        ] as $invalid) {
            $this->assertInvalid(static fn () => new PbsUpid($invalid));
        }
    }

    public function testJobObservationsAndListsEnforceVariantInvariants(): void
    {
        $prune = $this->job(PbsJobKind::Prune, 'prune_a');
        $sync = $this->job(PbsJobKind::Sync, 'sync_a');
        self::assertSame("prune\0prune_a", $prune->key());
        $snapshot = new PbsJobListSnapshot(PbsJobKind::Prune, str_repeat('a', 64), [$prune]);
        self::assertSame([$prune], $snapshot->jobs);
        self::assertSame(PbsSyncDirection::Pull, $sync->syncDirection);

        $this->assertInvalid(fn () => new PbsJobListSnapshot(PbsJobKind::Prune, 'bad', []));
        $this->assertInvalid(fn () => new PbsJobListSnapshot(PbsJobKind::Prune, str_repeat('a', 64), [$sync]));
        $this->assertInvalid(fn () => new PbsJobListSnapshot(PbsJobKind::Prune, str_repeat('a', 64), [$prune, $prune]));
        foreach ([
            fn () => $this->rawJob(PbsJobKind::Sync, null, null, null, null, null, null),
            fn () => $this->rawJob(PbsJobKind::Prune, null, new PbsJobId('remote_a'), null, null, null, null),
            fn () => $this->rawJob(PbsJobKind::Prune, PbsSyncDirection::Pull, null, null, null, null, null),
            fn () => $this->rawJob(
                PbsJobKind::Prune, null, null, null, null, null, null, new PbsDatastoreId('remote_a'),
            ),
            fn () => new PbsJobObservation(
                PbsJobKind::Prune, new PbsJobId('job_a'), new PbsDatastoreId('store_a'), null,
                null, false, null, null, null, new PbsNamespace('remote/ns'),
                null, null, null, null,
            ),
            fn () => $this->rawJob(PbsJobKind::Prune, null, null, null, null, null, "bad\n"),
            fn () => $this->rawJob(PbsJobKind::Prune, null, null, null, null, null, str_repeat('x', 257)),
            fn () => $this->rawJob(PbsJobKind::Prune, null, null, $this->upid(), null, null, null),
            fn () => $this->rawJob(PbsJobKind::Prune, null, null, null, PbsTaskOutcome::Ok, 1, null),
            fn () => $this->rawJob(PbsJobKind::Prune, null, null, null, null, -1, null),
            fn () => new PbsJobObservation(
                PbsJobKind::Prune, new PbsJobId('job_a'), new PbsDatastoreId('store_a'), null,
                'daily', false, null, null, null, null, null, null, null, -1,
            ),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }
    }

    public function testTaskLifecyclePagesAndMergesAreMonotonic(): void
    {
        $upid = $this->upid();
        $running = new PbsTaskObservation($upid, 'localhost', true, false, null, null);
        $terminal = new PbsTaskObservation($upid, 'pbs-four', false, true, PbsTaskOutcome::Ok, $upid->startTime + 1);
        self::assertTrue($running->isRunning());
        self::assertFalse($terminal->isRunning());
        self::assertSame('pbs-four', $running->merge($terminal)->reportedNode);
        self::assertSame(PbsTaskOutcome::Ok, $terminal->merge($running)->outcome);
        self::assertTrue($running->merge($terminal)->seenRunning);
        self::assertTrue($running->merge($terminal)->seenHistory);
        self::assertEquals($terminal, $terminal->merge(new PbsTaskObservation($upid, null, false, true, PbsTaskOutcome::Ok, $upid->startTime + 1)));
        $terminalWithoutEnd = new PbsTaskObservation(
            $upid,
            'localhost',
            false,
            true,
            PbsTaskOutcome::Ok,
            null,
        );
        self::assertSame(
            $upid->startTime + 1,
            $terminalWithoutEnd->merge($terminal)->endTime,
        );
        self::assertSame(
            $upid->startTime + 1,
            $terminal->merge($terminalWithoutEnd)->endTime,
        );
        self::assertSame(
            $upid->startTime + 1,
            $terminal->merge(new PbsTaskObservation(
                $upid, 'pbs-four', false, true, PbsTaskOutcome::Ok, $upid->startTime + 1,
            ))->endTime,
        );
        self::assertSame('localhost', (new PbsTaskObservation($upid, null, true, false, null, null))->merge($running)->reportedNode);
        self::assertSame([$running], (new PbsTaskPage([$running], 1))->tasks);
        $secondRunning = new PbsTaskObservation(
            $this->upid('backup', 'second'),
            'localhost',
            true,
            false,
            null,
            null,
        );
        self::assertCount(2, (new PbsTaskPage([$running, $secondRunning], 2))->tasks);
        self::assertSame(0, (new PbsTaskPage([], null, 0, str_repeat('a', 64)))->rawRowCount);

        self::assertFalse((new PbsTaskObservation($upid, null, false, true, PbsTaskOutcome::Ok, null))->isRunning());
        $this->assertInvalid(fn () => new PbsTaskObservation($upid, 'bad node', true, false, null, null));
        $this->assertInvalid(fn () => new PbsTaskObservation($upid, null, false, false, null, null));
        $this->assertInvalid(fn () => new PbsTaskObservation($upid, null, true, false, null, $upid->startTime));
        $this->assertInvalid(fn () => new PbsTaskObservation($upid, null, false, true, PbsTaskOutcome::Ok, $upid->startTime - 1));
        $this->assertInvalid(fn () => $running->merge(new PbsTaskObservation($this->upid('prune'), null, true, false, null, null)));
        $this->assertInvalid(fn () => $terminal->merge(new PbsTaskObservation($upid, null, false, true, PbsTaskOutcome::Error, $upid->startTime + 2)));
        $this->assertInvalid(fn () => new PbsTaskPage([], -1));
        $this->assertInvalid(fn () => new PbsTaskPage([$running, $running], 2));
        $this->assertInvalid(fn () => new PbsTaskPage([$running], 1, 0));
        $this->assertInvalid(fn () => new PbsTaskPage([], 0, 0, 'bad'));
    }

    public function testFiltersQueriesWindowsAndLimitsAreBounded(): void
    {
        self::assertTrue(PbsTaskFilterFamily::Backup->allows('backup'));
        self::assertFalse(PbsTaskFilterFamily::Backup->allows('tape-backup'));
        self::assertTrue(PbsTaskFilterFamily::Prune->allows('prune'));
        self::assertTrue(PbsTaskFilterFamily::Prune->allows('prunejob'));
        self::assertFalse(PbsTaskFilterFamily::Prune->allows('pruner'));
        self::assertTrue(PbsTaskFilterFamily::Sync->allows('syncjob'));
        self::assertFalse(PbsTaskFilterFamily::Sync->allows('realm-syncjob'));
        foreach (['verificationjob', 'verify', 'verify_group', 'verify_snapshot'] as $type) {
            self::assertTrue(PbsTaskFilterFamily::Verify->allows($type));
        }
        self::assertFalse(PbsTaskFilterFamily::Verify->allows('verify_other'));
        self::assertSame(PbsTaskFilterFamily::Verify, PbsTaskFilterFamily::forWorkerType('verify_snapshot'));
        self::assertNull(PbsTaskFilterFamily::forWorkerType('tape-backup'));

        $window = new PbsTaskWindow(10, 20);
        self::assertSame(10, $window->since);
        self::assertSame(256, (new PbsTasksAndJobsLimits())->pageSize);
        self::assertSame(86_400, (new PbsTasksAndJobsLimits())->maximumHistoryWindowSeconds);
        self::assertSame(PbsTaskPass::History, (new PbsTaskListQuery(
            PbsTaskFilterFamily::Backup, PbsTaskPass::History, 0, 50, $window,
        ))->pass);
        foreach ([[-1, 1, $window], [0, 0, $window], [0, 1001, $window], [0, 1, null]] as [$start, $limit, $queryWindow]) {
            $this->assertInvalid(fn () => new PbsTaskListQuery(
                PbsTaskFilterFamily::Backup, PbsTaskPass::History, $start, $limit, $queryWindow,
            ));
        }
        $this->assertInvalid(fn () => new PbsTaskListQuery(
            PbsTaskFilterFamily::Backup, PbsTaskPass::Running, 0, 1, $window,
        ));
        foreach ([[-1, 0], [2, 1]] as [$since, $until]) {
            $this->assertInvalid(fn () => new PbsTaskWindow($since, $until));
        }
        foreach ([[0, 1, 1, 1], [1001, 1, 1001, 1], [1, 0, 1, 1], [1, 65, 1, 1], [10, 1, 9, 1], [1, 1, 65_537, 1], [1, 1, 1, 0], [1, 1, 1, 65_537]] as $values) {
            $this->assertInvalid(fn () => new PbsTasksAndJobsLimits(...$values));
        }
        foreach ([0, 86_401] as $maximumWindow) {
            $this->assertInvalid(fn () => new PbsTasksAndJobsLimits(1, 1, 1, 1, $maximumWindow));
        }
    }

    public function testSnapshotsAndAclEvidenceAreExplicitlyPositiveOnly(): void
    {
        $task = new PbsTaskObservation($this->upid(), 'localhost', true, false, null, null);
        $terminal = new PbsTaskObservation($this->upid(), 'pbs-four', false, true, PbsTaskOutcome::Ok, $task->upid->startTime + 1);
        $issue = new PbsTaskScanIssue(
            PbsTaskFilterFamily::Backup,
            PbsTaskPass::History,
            PbsTaskScanIssueCode::NoProgress,
        );
        $snapshot = new PbsTaskScanSnapshot(new PbsTaskWindow(1, 2_000_000_000), [$task, $terminal], [$issue]);
        self::assertFalse($snapshot->isComplete());
        self::assertFalse($snapshot->permitsAbsenceDecisions());
        self::assertSame(PbsTaskOutcome::Ok, $snapshot->tasks[0]->outcome);
        self::assertSame([$issue], $snapshot->issues);
        self::assertTrue((new PbsTaskScanSnapshot(new PbsTaskWindow(1, 2), [], []))->isComplete());
        $errorTerminal = new PbsTaskObservation(
            $terminal->upid,
            null,
            false,
            true,
            PbsTaskOutcome::Error,
            $terminal->upid->startTime + 2,
        );
        $left = new PbsTaskScanSnapshot(
            new PbsTaskWindow(1, 2_000_000_000), [$terminal, $errorTerminal], [],
        );
        $right = new PbsTaskScanSnapshot(
            new PbsTaskWindow(1, 2_000_000_000), [$errorTerminal, $terminal], [],
        );
        self::assertSame($left->tasks[0]->outcome, $right->tasks[0]->outcome);

        $completeAcl = new PbsAclEvidence(
            new PbsEffectivePermission('/system/tasks', ['Sys.Audit' => false]),
            new PbsEffectivePermission('/datastore', ['Datastore.Audit' => true]),
            new PbsEffectivePermission('/remote', ['Remote.Audit' => true]),
        );
        self::assertTrue($completeAcl->hasBroadReadEvidence());
        self::assertTrue($completeAcl->hasTaskReadEvidence());
        self::assertTrue($completeAcl->hasDatastoreReadEvidence());
        self::assertTrue($completeAcl->hasRemoteReadEvidence());
        self::assertFalse($completeAcl->permitsAbsenceDecisions());
        $missing = new PbsAclEvidence(
            new PbsEffectivePermission('/wrong', []),
            new PbsEffectivePermission('/wrong', ['Datastore.Audit' => false]),
            new PbsEffectivePermission('/wrong', ['Remote.Audit' => false]),
        );
        self::assertSame([
            PbsAclIssueCode::MissingSystemTaskAudit,
            PbsAclIssueCode::MissingDatastoreAuditPropagation,
            PbsAclIssueCode::MissingRemoteAuditPropagation,
        ], $missing->issues);
        self::assertFalse($missing->hasBroadReadEvidence());
        self::assertFalse($missing->hasTaskReadEvidence());
        self::assertFalse($missing->hasDatastoreReadEvidence());
        self::assertFalse($missing->hasRemoteReadEvidence());
        $pathsWithoutPrivileges = new PbsAclEvidence(
            new PbsEffectivePermission('/system/tasks', []),
            new PbsEffectivePermission('/datastore', []),
            new PbsEffectivePermission('/remote', []),
        );
        self::assertFalse($pathsWithoutPrivileges->hasTaskReadEvidence());
        self::assertFalse($pathsWithoutPrivileges->hasDatastoreReadEvidence());
        self::assertFalse($pathsWithoutPrivileges->hasRemoteReadEvidence());

        $completeStream = new PbsTaskStreamResult(
            PbsTaskFilterFamily::Backup, PbsTaskPass::Running, PbsTaskStreamStatus::Complete,
            1, 2, 1, false, false, null,
        );
        self::assertTrue($completeStream->isComplete());
        $partialStream = new PbsTaskStreamResult(
            PbsTaskFilterFamily::Backup, PbsTaskPass::History, PbsTaskStreamStatus::Partial,
            2, 2, 1, true, true, PbsTaskScanIssueCode::RepeatedPage,
        );
        self::assertFalse($partialStream->isComplete());
        self::assertSame($partialStream, $partialStream->withIssue(PbsTaskScanIssueCode::NoProgress));
        self::assertSame(PbsTaskStreamStatus::Partial, $completeStream->withIssue(
            PbsTaskScanIssueCode::ConflictingTaskEvidence,
        )->status);
        $this->assertInvalid(fn () => new PbsTaskStreamResult(
            PbsTaskFilterFamily::Backup, PbsTaskPass::History, PbsTaskStreamStatus::Complete,
            1, 0, 1, false, false, null,
        ));
        $this->assertInvalid(fn () => new PbsTaskStreamResult(
            PbsTaskFilterFamily::Backup, PbsTaskPass::Running, PbsTaskStreamStatus::Failed,
            1, 0, 0, true, false, PbsTaskScanIssueCode::ReadFailed,
        ));
        $this->assertInvalid(fn () => new PbsTaskScanSnapshot(
            new PbsTaskWindow(1, 2), [], [], [$completeStream, $completeStream],
        ));
        $this->assertInvalid(fn () => new PbsTaskObservation(
            $this->upid('tape-backup'), null, false, true, PbsTaskOutcome::Ok, 1_759_061_953,
        ));
    }

    private function job(PbsJobKind $kind, string $id): PbsJobObservation
    {
        return new PbsJobObservation(
            $kind,
            new PbsJobId($id),
            new PbsDatastoreId('store_a'),
            null,
            'daily',
            false,
            PbsJobKind::Sync === $kind ? PbsSyncDirection::Pull : null,
            null,
            PbsJobKind::Sync === $kind ? new PbsDatastoreId('remote_a') : null,
            null,
            $this->upid($kind->value.'job', $id),
            PbsTaskOutcome::Ok,
            1_759_061_962,
            null,
        );
    }

    private function rawJob(
        PbsJobKind $kind,
        ?PbsSyncDirection $direction,
        ?PbsJobId $remote,
        ?PbsUpid $lastRun,
        ?PbsTaskOutcome $outcome,
        ?int $endTime,
        ?string $schedule,
        ?PbsDatastoreId $remoteStore = null,
    ): PbsJobObservation {
        return new PbsJobObservation(
            $kind,
            new PbsJobId('job_a'),
            new PbsDatastoreId('store_a'),
            null,
            $schedule,
            false,
            $direction,
            $remote,
            $remoteStore,
            null,
            $lastRun,
            $outcome,
            $endTime,
            null,
        );
    }

    private function upid(
        string $type = 'backup',
        string $id = 'store_a',
        string $auth = 'root@pam',
        string $pstart = '000F4240',
    ): PbsUpid {
        return new PbsUpid(sprintf(
            'UPID:pbs-four:0000002A:%s:FFFFFFFFFFFFFFFF:68D927C0:%s:%s:%s:',
            $pstart,
            $type,
            $id,
            $auth,
        ));
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            self::fail('The invalid PBS tasks/jobs value was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
