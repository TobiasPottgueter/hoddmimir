<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Execution;

use App\Application\Backup\Execution\BackupExecutionGate;
use App\Application\Backup\Execution\BackupSubmissionTransaction;
use App\Application\Backup\Execution\DefinitiveBackupFailureNotice;
use App\Application\Backup\Execution\ExecutorAclProbeCommand;
use App\Application\Backup\Execution\ExecutorPermissionEvidence;
use App\Application\Backup\Execution\ExecutorPermissionEvidenceStore;
use App\Application\Backup\Execution\ExistingSubmissionStatus;
use App\Application\Backup\Execution\ProbeExecutorPermissions;
use App\Application\Backup\Execution\PreparedBackupSubmission;
use App\Application\Backup\Execution\SubmissionExecutionResult;
use App\Application\Backup\Execution\SubmissionExecutionStatus;
use App\Application\Backup\Execution\SubmissionPreparation;
use App\Application\Backup\Execution\SubmissionPreparationStatus;
use App\Application\Backup\Execution\SubmitClaimedBackup;
use App\Application\Backup\Execution\SubmitClaimedBackupCommand;
use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveBackupCompression;
use App\Application\Proxmox\Pve\PveBackupFailureRecipients;
use App\Application\Proxmox\Pve\PveBackupMode;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionResult;
use App\Application\Proxmox\Pve\PveExecutorAclClient;
use App\Application\Proxmox\Pve\PveExecutorEffectivePermission;
use App\Application\Proxmox\Pve\PveExecutorPermissionReader;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveTaskStopResult;
use App\Application\Proxmox\Pve\PveUpid;
use App\Domain\Shared\Clock;
use App\Domain\Backup\ControlledRetryPolicy;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackupExecutionApplicationTest extends TestCase
{
    private const string ID = 'iiiiiiiiiiiiiiii';

    public function testAclReaderAcceptsPve7To9ShapesAndFailsClosedOnMalformedEvidence(): void
    {
        $reader = new PveExecutorPermissionReader();
        foreach ([
            ['/vms/101' => ['VM.Backup' => 1, 'VM.Audit' => 1]],
            (object) ['/vms/101' => (object) ['VM.Backup' => 1, 'future' => 0]],
            ['/vms/101' => ['VM.Backup' => 1, 'malformed' => '1']],
            ['/vms/101' => [0 => 1, '' => 1, 'string' => '1', 'invalid' => 2, 'VM.Backup' => 1]],
            ['/vms/101' => ['disabled' => 0, 'negative' => -1, 'boolean' => true, 'VM.Backup' => 1]],
        ] as $fixture) {
            $permission = $reader->read($fixture, '/vms/101');
            self::assertTrue($permission->grants('VM.Backup'));
            self::assertFalse($permission->grants('missing'));
        }
        foreach ([null, [], [0 => []], ['/other' => []], ['/vms/101' => [0 => 1]], ['/vms/101' => []]] as $invalid) {
            try {
                $reader->read($invalid, '/vms/101');
                if (['/vms/101' => []] !== $invalid) {
                    self::fail('Malformed ACL response accepted.');
                }
                self::addToAssertionCount(1);
            } catch (PveBackupApiFailure $failure) {
                self::assertSame(PveBackupApiFailureCode::InvalidResponse, $failure->failureCode);
            }
        }
        $this->assertInvalid(static fn () => new PveExecutorEffectivePermission('', []));
        $this->assertInvalid(static fn () => new PveExecutorEffectivePermission('relative', []));
        $this->assertInvalid(static fn () => new PveExecutorEffectivePermission('/vms/101', ['' => 1]));
        $this->assertInvalid(static fn () => new PveExecutorEffectivePermission('/vms/101', ['VM.Backup' => 2]));
        self::assertFalse((new PveExecutorEffectivePermission('/vms/101', ['VM.Backup' => 0]))->grants('VM.Backup'));
    }

    public function testProbeUsesConcreteGuestAndStoragePathsAndPersistsFreshEvidence(): void
    {
        $client = new FakeAclClient();
        $store = new InMemoryExecutorEvidenceStore();
        $probe = new ProbeExecutorPermissions($client, $store, new ExecutionClock());
        $evidence = $probe->execute($this->probeCommand());

        self::assertTrue($evidence->authorized);
        self::assertTrue($evidence->vmBackupAuthorized);
        self::assertTrue($evidence->datastoreAllocateAuthorized);
        self::assertSame(['/vms/101', '/storage/backup-store'], $client->paths);
        self::assertSame($evidence, $store->evidence);
        self::assertSame('2026-07-12T18:00:00+00:00', $evidence->observedAt->format(DATE_ATOM));

        $client->permissions['/vms/101'] = [];
        $client->permissions['/storage/backup-store'] = [];
        $blocked = $probe->execute($this->probeCommand());
        self::assertFalse($blocked->authorized);
        self::assertSame(['VM.Backup', 'Datastore.AllocateSpace'], $blocked->missingPermissions);
    }

    public function testManualBackupOccupiesSlotAndDefersWithoutCreatingRunThenAllowsStart(): void
    {
        $transaction = new RecordingSubmissionTransaction();
        $client = new FakeBackupClient();
        $client->activeTasks = [new \App\Application\Proxmox\Pve\PveBackupTask(
            PveUpid::parse('UPID:pve-a:00000001:00000002:67000000:vzdump:999:root@pam:'),
            \App\Application\Proxmox\Pve\PveTaskSource::Active, null, 'RUNNING',
        )];
        $service = new SubmitClaimedBackup(new MutableExecutionGate(true), $transaction, $client,
            new ControlledRetryPolicy(), new \App\Application\Backup\Execution\CheckBackupNodeTasks($client), new ExecutionClock());
        $result = $service->execute($this->submitCommand());
        self::assertSame('remote_backup_running', $result->blockerCode);
        self::assertSame(0, $client->submitCalls);
        self::assertSame(0, $transaction->prepareCalls);
        self::assertSame(['remote_backup_running'], $transaction->deferred);
        $client->activeTasks = [];
        $service->execute($this->submitCommand());
        self::assertSame(1, $client->submitCalls);
        self::assertSame(1, $transaction->prepareCalls);
        self::assertNotNull($transaction->preparedCommand?->taskEvidence);
    }

    public function testFailedTaskReadDefersWithoutSubmissionOrFailureAttempt(): void
    {
        $transaction = new RecordingSubmissionTransaction();
        $client = new FakeBackupClient();
        $client->taskFailure = PveBackupApiFailure::for(PveBackupApiFailureCode::PermissionDenied);
        $service = new SubmitClaimedBackup(new MutableExecutionGate(true), $transaction, $client,
            new ControlledRetryPolicy(), new \App\Application\Backup\Execution\CheckBackupNodeTasks($client), new ExecutionClock());
        self::assertSame('remote_tasks_unavailable', $service->execute($this->submitCommand())->blockerCode);
        self::assertSame(0, $client->submitCalls);
        self::assertSame(0, $transaction->prepareCalls);
        self::assertSame([], $transaction->notices);
    }

    public function testSlowTaskReadExpiresEvidenceBeforeAnyRunOrPost(): void
    {
        $transaction = new RecordingSubmissionTransaction();
        $client = new FakeBackupClient();
        $clock = new ExecutionClock();
        $client->onTaskRead = static function () use ($clock): void { $clock->at = $clock->now()->modify('+31 seconds'); };
        $service = new SubmitClaimedBackup(new MutableExecutionGate(true), $transaction, $client,
            new ControlledRetryPolicy(), new \App\Application\Backup\Execution\CheckBackupNodeTasks($client), $clock);
        self::assertSame('remote_tasks_stale', $service->execute($this->submitCommand())->blockerCode);
        self::assertSame(0, $client->submitCalls);
        self::assertSame(0, $transaction->prepareCalls);
    }

    public function testPartialTaskPageAndMissingScopeCannotCreateRun(): void
    {
        $transaction = new RecordingSubmissionTransaction();
        $client = new FakeBackupClient();
        $client->taskIssues = [new \App\Application\Proxmox\Pve\PveBackupInventoryIssue(
            \App\Application\Proxmox\Pve\PveBackupInventoryIssueCode::InvalidField,
            '/nodes/pve-a/tasks', '/data/0',
        )];
        $service = new SubmitClaimedBackup(new MutableExecutionGate(true), $transaction, $client,
            new ControlledRetryPolicy(), new \App\Application\Backup\Execution\CheckBackupNodeTasks($client), new ExecutionClock());
        self::assertSame('remote_tasks_unavailable', $service->execute($this->submitCommand())->blockerCode);
        $transaction->nodes = [];
        self::assertSame('remote_tasks_unavailable', $service->execute($this->submitCommand())->blockerCode);
        self::assertSame(0, $client->submitCalls);
        self::assertSame(0, $transaction->prepareCalls);
    }

    public function testEveryRequiredNodeIsReadAndUnknownScopeBlocks(): void
    {
        $client = new FakeBackupClient();
        $check = new \App\Application\Backup\Execution\CheckBackupNodeTasks($client);
        self::assertNull($check->blocker(self::ID, ['old-node', 'new-node', 'old-node']));
        self::assertSame(['old-node', 'new-node'], $client->taskNodes);
        self::assertSame('remote_tasks_unavailable', $check->blocker(self::ID, []));
        $client->rawTaskCount = 1;
        self::assertSame('remote_tasks_unavailable', $check->blocker(self::ID, ['node-a']));
    }

    public function testExecutionGateAndPreparationPreventEveryWriteBeforePreparedNow(): void
    {
        $gate = new MutableExecutionGate(false);
        $transaction = new RecordingSubmissionTransaction();
        $client = new FakeBackupClient();
        $orchestrator = new SubmitClaimedBackup($gate, $transaction, $client, new ControlledRetryPolicy(), new \App\Application\Backup\Execution\CheckBackupNodeTasks($client), new ExecutionClock());

        self::assertSame(SubmissionExecutionStatus::Disabled, $orchestrator->execute($this->submitCommand())->status);
        self::assertSame(0, $client->submitCalls);
        self::assertSame(0, $transaction->prepareCalls);
        self::assertSame(1, $transaction->inspectCalls);

        $transaction->existing = ExistingSubmissionStatus::RecoveryRequired;
        self::assertSame(SubmissionExecutionStatus::RecoveryRequired, $orchestrator->execute($this->submitCommand())->status);
        self::assertSame(0, $transaction->prepareCalls);
        self::assertSame(0, $client->submitCalls);
        $transaction->existing = ExistingSubmissionStatus::FreshClaim;

        $gate->value = true;
        $transaction->preparation = new SubmissionPreparation(SubmissionPreparationStatus::Blocked, 'capacity_stale');
        $blocked = $orchestrator->execute($this->submitCommand());
        self::assertSame(SubmissionExecutionStatus::Blocked, $blocked->status);
        self::assertSame('capacity_stale', $blocked->blockerCode);
        self::assertSame(0, $client->submitCalls);

        $transaction->preparation = new SubmissionPreparation(SubmissionPreparationStatus::RecoveryRequired);
        self::assertSame(SubmissionExecutionStatus::RecoveryRequired, $orchestrator->execute($this->submitCommand())->status);
        self::assertSame(0, $client->submitCalls, 'An existing awaiting_submission state must never be POSTed again.');
    }

    public function testPreparedSubmissionPerformsExactlyOnePostAndPersistsEveryProvenance(): void
    {
        $gate = new MutableExecutionGate(true);
        $transaction = new RecordingSubmissionTransaction();
        $client = new FakeBackupClient();
        $orchestrator = new SubmitClaimedBackup($gate, $transaction, $client, new ControlledRetryPolicy(), new \App\Application\Backup\Execution\CheckBackupNodeTasks($client), new ExecutionClock());

        $upid = PveUpid::parse('UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:backup@pve:');
        $client->result = PveBackupSubmissionResult::accepted($upid);
        $accepted = $orchestrator->execute($this->submitCommand());
        self::assertSame(SubmissionExecutionStatus::Accepted, $accepted->status);
        self::assertSame($upid, $accepted->upid);
        self::assertSame(1, $client->submitCalls);
        self::assertSame($transaction->preparation->submission?->payload, $client->lastSubmission,
            'Only the payload built from locked persistence may reach PVE.');
        self::assertSame(['accepted'], $transaction->records);

        $client->result = PveBackupSubmissionResult::ambiguous();
        self::assertSame(SubmissionExecutionStatus::Ambiguous, $orchestrator->execute($this->submitCommand())->status);
        self::assertSame(2, $client->submitCalls);
        self::assertSame(['accepted', 'ambiguous:none'], $transaction->records);

        $client->failure = PveBackupApiFailure::for(PveBackupApiFailureCode::PermissionDenied);
        self::assertSame(SubmissionExecutionStatus::DefinitiveRejection, $orchestrator->execute($this->submitCommand())->status);
        self::assertSame(['accepted', 'ambiguous:none', 'rejected:permission_denied'], $transaction->records);
        self::assertCount(1, $transaction->notices);
        self::assertSame(1, $transaction->notices[0]->attempt);
        self::assertSame('Guest 101', $transaction->notices[0]->guestName);
        self::assertSame(101, $transaction->notices[0]->vmid);
        self::assertSame(PveGuestType::Qemu, $transaction->notices[0]->guestType);
        self::assertSame('pve-a', $transaction->notices[0]->node);
        self::assertSame('Primary target', $transaction->notices[0]->targetLabel);
        self::assertSame('2026-07-12T18:01:00+00:00', $transaction->notices[0]->nextRetryAt->format(DATE_ATOM));

        foreach ([
            PveBackupApiFailureCode::Transport,
            PveBackupApiFailureCode::RemoteUnavailable,
            PveBackupApiFailureCode::InvalidEnvelope,
            PveBackupApiFailureCode::InvalidResponse,
            PveBackupApiFailureCode::RateLimited,
            PveBackupApiFailureCode::Authentication,
            PveBackupApiFailureCode::Configuration,
        ] as $definitive) {
            $client->failure = PveBackupApiFailure::for($definitive);
            self::assertSame(SubmissionExecutionStatus::DefinitiveRejection, $orchestrator->execute($this->submitCommand())->status);
            self::assertSame('rejected:'.$definitive->value, $transaction->records[array_key_last($transaction->records)]);
        }
        self::assertSame(10, $client->submitCalls, 'No ambiguous outcome triggered an automatic second POST.');
        self::assertCount(8, $transaction->notices, 'Every definitive failed attempt must persist exactly one Matrix outbox notice.');
    }

    public function testExecutionDtosRejectContradictoryStates(): void
    {
        foreach ([
            fn () => new SubmissionPreparation(SubmissionPreparationStatus::PreparedNow, 'blocked', $this->prepared()),
            static fn () => new SubmissionPreparation(SubmissionPreparationStatus::PreparedNow),
            fn () => new SubmissionPreparation(SubmissionPreparationStatus::RecoveryRequired, submission: $this->prepared()),
            static fn () => new SubmissionPreparation(SubmissionPreparationStatus::Blocked),
            static fn () => new SubmissionPreparation(SubmissionPreparationStatus::Blocked, 'Bad blocker'),
            static fn () => new SubmissionPreparation(SubmissionPreparationStatus::Blocked, ''),
            static fn () => new SubmissionExecutionResult(SubmissionExecutionStatus::Accepted),
            static fn () => new SubmissionExecutionResult(SubmissionExecutionStatus::Disabled, blockerCode: 'blocked'),
            fn () => new ExecutorAclProbeCommand('bad', self::ID, self::ID, self::ID, self::ID, self::ID, $this->submission()),
            fn () => new PreparedBackupSubmission($this->submission(), 0, self::ID, self::ID, 'Target', 'Guest'),
            fn () => new PreparedBackupSubmission($this->submission(), 1, 'bad', self::ID, 'Target', 'Guest'),
            fn () => new PreparedBackupSubmission($this->submission(), 1, self::ID, 'bad', 'Target', 'Guest'),
            fn () => new PreparedBackupSubmission($this->submission(), 1, self::ID, self::ID, ' ', 'Guest'),
            fn () => new PreparedBackupSubmission($this->submission(), 1, self::ID, self::ID, '', 'Guest'),
            fn () => new PreparedBackupSubmission($this->submission(), 1, self::ID, self::ID, str_repeat('x', 191), 'Guest'),
            fn () => new PreparedBackupSubmission($this->submission(), 1, self::ID, self::ID, 'Target', ' '),
            fn () => new PreparedBackupSubmission($this->submission(), 1, self::ID, self::ID, 'Target', ''),
            fn () => new PreparedBackupSubmission($this->submission(), 1, self::ID, self::ID, 'Target', str_repeat('x', 191)),
            fn () => new DefinitiveBackupFailureNotice(0, self::ID, 'Guest', 101, PveGuestType::Qemu, 'pve-a', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, 'Guest', 101, PveGuestType::Qemu, 'pve-a', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:00:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, 'bad', 'Guest', 101, PveGuestType::Qemu, 'pve-a', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, 'Guest', 101, PveGuestType::Qemu, 'pve-a', 'bad',
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, 'Guest', 101, PveGuestType::Qemu, 'pve-a', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T20:00:00+02:00'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, 'Guest', 101, PveGuestType::Qemu, 'pve-a', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T20:01:00+02:00')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, 'Guest', 101, PveGuestType::Qemu, ' ', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, 'Guest', 101, PveGuestType::Qemu, '', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, 'Guest', 101, PveGuestType::Qemu, 'pve-a', self::ID,
                ' ', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, 'Guest', 101, PveGuestType::Qemu, 'pve-a', self::ID,
                '', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, ' ', 101, PveGuestType::Qemu, 'pve-a', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, '', 101, PveGuestType::Qemu, 'pve-a', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            fn () => new DefinitiveBackupFailureNotice(1, self::ID, str_repeat('x', 191), 101, PveGuestType::Qemu, 'pve-a', self::ID,
                'Target', PveBackupApiFailureCode::HttpStatus, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                new DateTimeImmutable('2026-07-12T18:01:00Z')),
            static fn () => new SubmitClaimedBackupCommand('bad', self::ID, self::ID, 1, new DateTimeImmutable('2026-07-12T18:00:00Z')),
            static fn () => new SubmitClaimedBackupCommand(self::ID, self::ID, self::ID, 0, new DateTimeImmutable('2026-07-12T18:00:00Z')),
            static fn () => new SubmitClaimedBackupCommand(self::ID, self::ID, self::ID, 1, new DateTimeImmutable('2026-07-12T20:00:00+02:00')),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, true, true, true,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '/storage/store', ['VM.Backup']),
            fn () => new ExecutorPermissionEvidence('bad', self::ID, self::ID, self::ID, self::ID, self::ID, true, true, true,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '/storage/store', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, true, true, true,
                new DateTimeImmutable('2026-07-12T20:00:00+02:00'), '/vms/101', '/storage/store', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, true, true, true,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/bad', '/storage/store', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, true, true, true,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '', '/storage/store', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, true, true, true,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '/bad', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, true, true, true,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, true, true, false,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '/storage/store', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, false, true, false,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '/storage/store', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, true, false, false,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '/storage/store', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, true, true, true,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '/storage/store', ['unknown']),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, false, false, false,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '/storage/store', []),
            fn () => new ExecutorPermissionEvidence(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, false, false, false,
                new DateTimeImmutable('2026-07-12T18:00:00Z'), '/vms/101', '/storage/store', ['VM.Backup']),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }

        foreach ([
            [false, true, false, ['VM.Backup']],
            [true, false, false, ['Datastore.AllocateSpace']],
            [false, false, false, ['Datastore.AllocateSpace', 'VM.Backup']],
        ] as [$vmBackup, $datastoreAllocate, $authorized, $missing]) {
            self::assertInstanceOf(ExecutorPermissionEvidence::class, new ExecutorPermissionEvidence(
                self::ID, self::ID, self::ID, self::ID, self::ID, self::ID,
                $vmBackup, $datastoreAllocate, $authorized, new DateTimeImmutable('2026-07-12T18:00:00Z'),
                '/vms/101', '/storage/store', $missing,
            ));
        }
    }

    private function probeCommand(): ExecutorAclProbeCommand
    {
        return new ExecutorAclProbeCommand(self::ID, self::ID, self::ID, self::ID, self::ID, self::ID, $this->submission());
    }

    private function submitCommand(): SubmitClaimedBackupCommand
    {
        return new SubmitClaimedBackupCommand(
            self::ID, str_repeat('r', 16), str_repeat('t', 16), 1,
            new DateTimeImmutable('2026-07-12T18:00:00Z'),
        );
    }

    private function submission(): PveBackupSubmission
    {
        return new PveBackupSubmission(
            'pve-a', 101, PveGuestType::Qemu, 'backup-store',
            PveBackupMode::Snapshot, PveBackupCompression::Zstd,
            new PveBackupFailureRecipients(['ops@example.invalid']), legacyMaxFiles: 1,
        );
    }

    private function prepared(): PreparedBackupSubmission
    {
        return new PreparedBackupSubmission($this->submission(), 1, str_repeat('g', 16), str_repeat('b', 16), 'Primary target', 'Guest 101');
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            self::fail('Invalid execution contract accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}

final class MutableExecutionGate implements BackupExecutionGate
{
    public function __construct(public bool $value) {}
    public function enabled(): bool { return $this->value; }
}

final class RecordingSubmissionTransaction implements BackupSubmissionTransaction
{
    public SubmissionPreparation $preparation;
    public ExistingSubmissionStatus $existing = ExistingSubmissionStatus::FreshClaim;
    public int $inspectCalls = 0;
    /** @var list<string> */ public array $nodes = ['pve-a'];
    public int $prepareCalls = 0;
    /** @var list<string> */ public array $deferred = [];
    public ?SubmitClaimedBackupCommand $preparedCommand = null;
    /** @var list<string> */ public array $records = [];

    /** @var list<DefinitiveBackupFailureNotice> */ public array $notices = [];
    public function __construct() { $this->preparation = new SubmissionPreparation(SubmissionPreparationStatus::PreparedNow, submission: new PreparedBackupSubmission(new PveBackupSubmission('pve-a', 101, PveGuestType::Qemu, 'backup-store', PveBackupMode::Snapshot, PveBackupCompression::Zstd, new PveBackupFailureRecipients(['ops@example.invalid']), legacyMaxFiles: 1), 1, str_repeat('g', 16), str_repeat('b', 16), 'Primary target', 'Guest 101')); }
    public function inspectExistingSubmission(SubmitClaimedBackupCommand $command): ExistingSubmissionStatus { ++$this->inspectCalls; return $this->existing; }
    public function taskInspectionNodes(SubmitClaimedBackupCommand $command): array { return $this->nodes; }
    public function deferRemoteTaskCheck(SubmitClaimedBackupCommand $command, string $blocker): void { $this->deferred[] = $blocker; }
    public function prepareAfterFullRevalidation(SubmitClaimedBackupCommand $command): SubmissionPreparation { ++$this->prepareCalls; $this->preparedCommand = $command; return $this->preparation; }
    public function recordAccepted(SubmitClaimedBackupCommand $command, PveUpid $upid): void { $this->records[] = 'accepted'; }
    public function recordDefinitiveRejection(SubmitClaimedBackupCommand $command, PveBackupApiFailureCode $failure, DefinitiveBackupFailureNotice $notice): void { $this->records[] = 'rejected:'.$failure->value; $this->notices[] = $notice; }
    public function recordAmbiguous(SubmitClaimedBackupCommand $command, ?PveBackupApiFailureCode $failure): void { $this->records[] = 'ambiguous:'.(null === $failure ? 'none' : $failure->value); }
}

final class FakeBackupClient implements PveBackupClient, PveBackupClientProvider
{
    public int $submitCalls = 0;
    /** @var list<\App\Application\Proxmox\Pve\PveBackupTask> */ public array $activeTasks = [];
    /** @var list<string> */ public array $taskNodes = [];
    public ?PveBackupApiFailure $taskFailure = null;
    public int $rawTaskCount = 0;
    /** @var list<\App\Application\Proxmox\Pve\PveBackupInventoryIssue> */ public array $taskIssues = [];
    public ?\Closure $onTaskRead = null;
    public PveBackupSubmissionResult $result;
    public ?PveBackupApiFailure $failure = null;
    public ?PveBackupSubmission $lastSubmission = null;
    public function __construct() { $this->result = PveBackupSubmissionResult::ambiguous(); }
    public function submit(PveBackupSubmission $submission): PveBackupSubmissionResult { ++$this->submitCalls; $this->lastSubmission = $submission; if (null !== $this->failure) { $failure = $this->failure; $this->failure = null; throw $failure; } return $this->result; }
    public function forRequest(string $requestId): PveBackupClient { return $this; }
    public function taskStatus(PveUpid $upid): PveTaskStatus { throw new \LogicException(); }
    public function taskLog(PveUpid $upid, PveTaskLogQuery $query): PveTaskLogPage { throw new \LogicException(); }
    public function taskPage(string $node, PveTaskQuery $query): PveTaskPage { $this->taskNodes[] = $node; if (null !== $this->onTaskRead) ($this->onTaskRead)(); if (null !== $this->taskFailure) throw $this->taskFailure; return new PveTaskPage($query, max($this->rawTaskCount, count($this->activeTasks)), $this->activeTasks, $this->taskIssues); }
    public function stopTask(PveUpid $upid): PveTaskStopResult { throw new \LogicException(); }
}

final class FakeAclClient implements PveExecutorAclClient
{
    /** @var array<string, array<string, int>> */ public array $permissions = [
        '/vms/101' => ['VM.Backup' => 1], '/storage/backup-store' => ['Datastore.AllocateSpace' => 1],
    ];
    /** @var list<string> */ public array $paths = [];
    public function permission(string $path): PveExecutorEffectivePermission { $this->paths[] = $path; return new PveExecutorEffectivePermission($path, $this->permissions[$path] ?? []); }
}

final class InMemoryExecutorEvidenceStore implements ExecutorPermissionEvidenceStore
{
    public ?ExecutorPermissionEvidence $evidence = null;
    public function persist(ExecutorPermissionEvidence $evidence): void { $this->evidence = $evidence; }
}

final class ExecutionClock implements Clock
{
    public ?DateTimeImmutable $at = null;
    public function now(): DateTimeImmutable { return $this->at ?? new DateTimeImmutable('2026-07-12T18:00:00Z'); }
}
