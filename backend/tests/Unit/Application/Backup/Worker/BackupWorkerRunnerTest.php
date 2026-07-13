<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Worker;

use App\Application\Backup\Execution\BackupExecutionGate;
use App\Application\Backup\Execution\BackupSubmissionTransaction;
use App\Application\Backup\Execution\DefinitiveBackupFailureNotice;
use App\Application\Backup\Execution\ExistingSubmissionStatus;
use App\Application\Backup\Execution\PreparedBackupSubmission;
use App\Application\Backup\Execution\SubmissionPreparation;
use App\Application\Backup\Execution\SubmissionPreparationStatus;
use App\Application\Backup\Execution\SubmitClaimedBackup;
use App\Application\Backup\Execution\SubmitClaimedBackupCommand;
use App\Application\Backup\Monitoring\AmbiguousSubmissionEvidence;
use App\Application\Backup\Monitoring\AmbiguousSubmissionIdentity;
use App\Application\Backup\Monitoring\AmbiguousSubmissionReconciliationStore;
use App\Application\Backup\Monitoring\AmbiguousSubmissionTaskSource;
use App\Application\Backup\Monitoring\BackupMonitoringTransaction;
use App\Application\Backup\Monitoring\MonitorClaimedBackup;
use App\Application\Backup\Monitoring\MonitorClaimedBackupCommand;
use App\Application\Backup\Monitoring\PreparedBackupMonitoring;
use App\Application\Backup\Monitoring\PveTaskStatusClassifier;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmission;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmissionCommand;
use App\Application\Backup\Queue\BackupQueueStore;
use App\Application\Backup\Queue\ClaimNextBackupCommand;
use App\Application\Backup\Queue\ClaimedBackupRequest;
use App\Application\Backup\Queue\FinalizeClaimedBackupCommand;
use App\Application\Backup\Queue\ShadowPromotion;
use App\Application\Backup\Worker\BackupNotificationDeliveryHook;
use App\Application\Backup\Worker\BackupRunIdentifierSource;
use App\Application\Backup\Worker\BackupWorkerRunner;
use App\Application\Backup\Worker\BackupWorkerTickStatus;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveBackupCompression;
use App\Application\Proxmox\Pve\PveBackupMode;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionResult;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveTaskStopResult;
use App\Application\Proxmox\Pve\PveUpid;
use App\Domain\Backup\ControlledRetryPolicy;
use App\Domain\Backup\MonitoringOutcome;
use App\Domain\Backup\RecoveryOutcome;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupWorkerRunnerTest extends TestCase
{
    public function testNoWorkStillDeliversOneNotificationAndHonoursExecutionGate(): void
    {
        $queue = new RunnerQueue();
        $notifications = new RunnerNotifications();
        $runner = $this->runner($queue, false, $notifications);

        self::assertSame(BackupWorkerTickStatus::NoWork, $runner->runOnce(self::id('worker')));
        self::assertFalse($queue->allowNewClaims);
        self::assertSame(1, $notifications->calls);
    }

    #[DataProvider('activeStates')]
    public function testRoutesExistingRunsToMonitoringOrReconciliation(string $state, BackupWorkerTickStatus $expected): void
    {
        $queue = new RunnerQueue($this->claim($state));
        $notifications = new RunnerNotifications();
        self::assertSame($expected, $this->runner($queue, true, $notifications)->runOnce(self::id('worker')));
        self::assertSame(1, $notifications->calls);
    }

    /** @return iterable<string, array{string, BackupWorkerTickStatus}> */
    public static function activeStates(): iterable
    {
        yield 'running' => ['running', BackupWorkerTickStatus::Monitored];
        yield 'starting' => ['starting', BackupWorkerTickStatus::Reconciled];
        yield 'reconcile' => ['reconcile_required', BackupWorkerTickStatus::Reconciled];
    }

    public function testRoutesLeasedSubmissionResultsAndValidatesIdentifiers(): void
    {
        $queue = new RunnerQueue($this->claim('leased'));
        self::assertSame(BackupWorkerTickStatus::Submitted, $this->runner($queue, true, new RunnerNotifications())->runOnce(self::id('worker')));

        $transaction = new RunnerSubmissionTransaction();
        $transaction->existing = ExistingSubmissionStatus::RecoveryRequired;
        self::assertSame(
            BackupWorkerTickStatus::SubmissionDeferred,
            $this->runner(new RunnerQueue($this->claim('leased')), true, new RunnerNotifications(), $transaction)->runOnce(self::id('worker')),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->runner(new RunnerQueue(), true, new RunnerNotifications())->runOnce('bad');
    }

    public function testInvalidGeneratedRunIdFailsBeforeSubmission(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runner(
            new RunnerQueue($this->claim('leased')),
            true,
            new RunnerNotifications(),
            identifiers: new RunnerIds('bad'),
        )->runOnce(self::id('worker'));
    }

    private function runner(RunnerQueue $queue, bool $enabled, RunnerNotifications $notifications, ?RunnerSubmissionTransaction $submission = null, ?RunnerIds $identifiers = null): BackupWorkerRunner
    {
        $clock = new RunnerClock($this->now());
        $transaction = $submission ?? new RunnerSubmissionTransaction();
        $client = new RunnerClient();
        return new BackupWorkerRunner(
            $queue,
            new RunnerExecutionGate($enabled),
            new SubmitClaimedBackup(new RunnerExecutionGate($enabled), $transaction, $client, new ControlledRetryPolicy()),
            new MonitorClaimedBackup(new RunnerMonitoringTransaction(), $client, new PveTaskStatusClassifier(), $clock),
            new ReconcileAmbiguousSubmission(new RunnerReconciliationStore(), new RunnerReconciliationSource(), $clock),
            $identifiers ?? new RunnerIds(),
            $notifications,
            $clock,
        );
    }

    private function claim(string $state): ClaimedBackupRequest
    {
        return new ClaimedBackupRequest(self::id('request'), self::id('token'), 1, $this->now()->modify('+2 minutes'), self::id('node'), self::id('target'), '1000', $state, 'leased' === $state ? null : self::id('run'));
    }
    private function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-13T10:00:00Z'); }
    private static function id(string $label): string { return substr(hash('sha256', $label, true), 0, 16); }
}

final class RunnerQueue implements BackupQueueStore
{
    public bool $allowNewClaims = true;
    public function __construct(private ?ClaimedBackupRequest $claim = null) {}
    public function promote(ShadowPromotion $promotion): string { return $promotion->requestId; }
    public function claim(ClaimNextBackupCommand $command): ?ClaimedBackupRequest { $this->allowNewClaims = $command->allowNewClaims; return $this->claim; }
    public function finalize(FinalizeClaimedBackupCommand $command): bool { return true; }
}
final readonly class RunnerExecutionGate implements BackupExecutionGate { public function __construct(private bool $enabled) {} public function enabled(): bool { return $this->enabled; } }
final class RunnerIds implements BackupRunIdentifierSource { public function __construct(private ?string $value = null) {} public function next(): string { return $this->value ?? substr(hash('sha256', 'runner-run', true), 0, 16); } }
final class RunnerNotifications implements BackupNotificationDeliveryHook { public int $calls=0; public function deliverOne(DateTimeImmutable $now): void { ++$this->calls; } }
final readonly class RunnerClock implements Clock { public function __construct(private DateTimeImmutable $now) {} public function now(): DateTimeImmutable { return $this->now; } }

final class RunnerSubmissionTransaction implements BackupSubmissionTransaction
{
    public ExistingSubmissionStatus $existing = ExistingSubmissionStatus::FreshClaim;
    public function inspectExistingSubmission(SubmitClaimedBackupCommand $command): ExistingSubmissionStatus { return $this->existing; }
    public function prepareAfterFullRevalidation(SubmitClaimedBackupCommand $command): SubmissionPreparation { return new SubmissionPreparation(SubmissionPreparationStatus::PreparedNow, submission: new PreparedBackupSubmission(new PveBackupSubmission('node-a',100,PveGuestType::Qemu,'backup',PveBackupMode::Snapshot,PveBackupCompression::Zstd),1,str_repeat('g',16),str_repeat('t',16),'Target','Guest')); }
    public function recordAccepted(SubmitClaimedBackupCommand $command, PveUpid $upid): void {}
    public function recordDefinitiveRejection(SubmitClaimedBackupCommand $command, PveBackupApiFailureCode $failure, DefinitiveBackupFailureNotice $notice): void {}
    public function recordAmbiguous(SubmitClaimedBackupCommand $command, ?PveBackupApiFailureCode $failure): void {}
}
final class RunnerClient implements PveBackupClient, PveBackupClientProvider
{
    public function forRequest(string $requestId): PveBackupClient { return $this; }
    public function submit(PveBackupSubmission $submission): PveBackupSubmissionResult { return PveBackupSubmissionResult::ambiguous(); }
    public function taskStatus(PveUpid $upid): PveTaskStatus { throw new \LogicException('unused'); }
    public function taskLog(PveUpid $upid, PveTaskLogQuery $query): PveTaskLogPage { throw new \LogicException('unused'); }
    public function stopTask(PveUpid $upid): PveTaskStopResult { throw new \LogicException('unused'); }
    public function taskPage(string $node, PveTaskQuery $query): PveTaskPage { throw new \LogicException('unused'); }
}
final class RunnerMonitoringTransaction implements BackupMonitoringTransaction
{
    public function renew(MonitorClaimedBackupCommand $command): bool { return true; }
    public function prepare(MonitorClaimedBackupCommand $command): ?PreparedBackupMonitoring { return null; }
    public function claimStopAttempt(MonitorClaimedBackupCommand $command, PveUpid $upid): bool { return false; }
    public function appendLogPage(MonitorClaimedBackupCommand $command, PveUpid $upid, PveTaskLogPage $page): void {}
    public function recordStopAttempt(MonitorClaimedBackupCommand $command, PveUpid $upid, ?\App\Application\Proxmox\Pve\PveTaskStopStatus $status, ?PveBackupApiFailureCode $failure): void {}
    public function recordObservation(MonitorClaimedBackupCommand $command, PveUpid $upid, MonitoringOutcome $outcome, ?string $exitStatus, ?PveBackupApiFailureCode $failure): void {}
}
final class RunnerReconciliationStore implements AmbiguousSubmissionReconciliationStore
{
    public function renew(ReconcileAmbiguousSubmissionCommand $command): bool { return true; }
    public function prepare(ReconcileAmbiguousSubmissionCommand $command): ?AmbiguousSubmissionIdentity { return null; }
    public function record(ReconcileAmbiguousSubmissionCommand $command, RecoveryOutcome $outcome): void {}
}
final class RunnerReconciliationSource implements AmbiguousSubmissionTaskSource
{
    public function read(AmbiguousSubmissionIdentity $identity, callable $beforePage): AmbiguousSubmissionEvidence { return new AmbiguousSubmissionEvidence(false, []); }
}
