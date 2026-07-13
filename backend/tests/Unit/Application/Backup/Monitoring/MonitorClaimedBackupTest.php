<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Monitoring;

use App\Application\Backup\Monitoring\BackupMonitoringTransaction;
use App\Application\Backup\Monitoring\MonitorClaimedBackup;
use App\Application\Backup\Monitoring\MonitorClaimedBackupCommand;
use App\Application\Backup\Monitoring\MonitoringTickResult;
use App\Application\Backup\Monitoring\MonitoringTickStatus;
use App\Application\Backup\Monitoring\PreparedBackupMonitoring;
use App\Application\Backup\Monitoring\PveTaskStatusClassifier;
use App\Application\Backup\Monitoring\StopAttemptDisposition;
use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveBackupInventoryIssue;
use App\Application\Proxmox\Pve\PveBackupInventoryIssueCode;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionResult;
use App\Application\Proxmox\Pve\PveTaskLifecycle;
use App\Application\Proxmox\Pve\PveTaskLogEntry;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveTaskStopResult;
use App\Application\Proxmox\Pve\PveTaskStopStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Domain\Backup\MonitoringOutcome;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Tests\Fakes\FrozenClock;
use App\Domain\Shared\Clock;

final class MonitorClaimedBackupTest extends TestCase
{
    public function testNoPreparedRunPerformsNoPveIo(): void
    {
        $transaction = new MonitoringTransactionFake();
        $client = new MonitoringClientFake();

        $result = $this->service($transaction, $client)->execute($this->command());

        self::assertSame(MonitoringTickStatus::NoWork, $result->status);
        self::assertSame(0, $client->calls());
        self::assertSame([], $transaction->observations);
    }

    public function testRunningTaskAppendsOneBoundedLogPageAndObservation(): void
    {
        $transaction = $this->prepared(7);
        $client = new MonitoringClientFake();
        $client->logEntries = [new PveTaskLogEntry(7, 'line seven'), new PveTaskLogEntry(8, 'line eight')];

        $result = $this->service($transaction, $client, 25)->execute($this->command());

        self::assertSame(MonitoringTickStatus::Running, $result->status);
        self::assertSame(2, $result->logEntries);
        self::assertSame(['log:7:25', 'observation:running:none:none'], $transaction->events);
        self::assertSame(1, $client->logCalls);
        self::assertSame(1, $client->statusCalls);
    }

    public function testIndependentLogAndStatusFailuresKeepRunNonTerminal(): void
    {
        $transaction = $this->prepared();
        $client = new MonitoringClientFake();
        $client->logFailure = PveBackupApiFailureCode::RemoteUnavailable;
        $client->statusFailure = PveBackupApiFailureCode::Transport;

        $result = $this->service($transaction, $client)->execute($this->command());

        self::assertSame(MonitoringTickStatus::TemporarilyUnavailable, $result->status);
        self::assertSame(PveBackupApiFailureCode::RemoteUnavailable, $result->logFailure);
        self::assertSame(PveBackupApiFailureCode::Transport, $result->statusFailure);
        self::assertSame(['observation:temporarily_unavailable:none:transport'], $transaction->events);
    }

    public function testProviderFailureIsTemporaryAndTheNextTickContinues(): void
    {
        $transaction = $this->prepared();
        $client = new MonitoringClientFake();
        $client->providerFailure = PveBackupApiFailureCode::CredentialUnavailable;

        $first = $this->service($transaction, $client)->execute($this->command());
        $second = $this->service($transaction, $client)->execute($this->command());

        self::assertSame(MonitoringTickStatus::TemporarilyUnavailable, $first->status);
        self::assertSame(PveBackupApiFailureCode::CredentialUnavailable, $first->statusFailure);
        self::assertSame(MonitoringTickStatus::Running, $second->status);
        self::assertSame([
            MonitoringOutcome::TemporarilyUnavailable,
            MonitoringOutcome::Running,
        ], $transaction->observations);
    }

    #[DataProvider('terminalStatusProvider')]
    public function testTerminalStatusIsAppliedExactlyOnce(
        bool $cancelRequested,
        string $exitStatus,
        MonitoringTickStatus $expected,
        MonitoringOutcome $outcome,
    ): void {
        $transaction = $this->prepared(
            0,
            $cancelRequested ? StopAttemptDisposition::AlreadyAttempted : StopAttemptDisposition::NotRequested,
        );
        $client = new MonitoringClientFake();
        $client->taskStatus = $this->taskStatus(PveTaskLifecycle::Stopped, $exitStatus);

        $result = $this->service($transaction, $client)->execute($this->command());

        self::assertSame($expected, $result->status);
        self::assertSame($outcome, $transaction->observations[0]);
        self::assertSame(0, $client->stopCalls);
    }

    /** @return iterable<string, array{bool, string, MonitoringTickStatus, MonitoringOutcome}> */
    public static function terminalStatusProvider(): iterable
    {
        yield 'success wins even after cancellation request' => [true, 'OK', MonitoringTickStatus::Succeeded, MonitoringOutcome::Succeeded];
        yield 'failure' => [false, 'ERROR', MonitoringTickStatus::Failed, MonitoringOutcome::Failed];
        yield 'cancelled' => [true, 'interrupted by signal', MonitoringTickStatus::Cancelled, MonitoringOutcome::Cancelled];
    }

    #[DataProvider('stopResultProvider')]
    public function testClaimedStopIsAttemptedExactlyOnceAndMonitoringContinues(
        ?PveTaskStopResult $stopResult,
        ?PveBackupApiFailureCode $stopFailure,
        ?PveTaskStopStatus $expectedStatus,
    ): void {
        $transaction = $this->prepared(0, StopAttemptDisposition::ReadyToClaim);
        $client = new MonitoringClientFake();
        $client->stopResult = $stopResult;
        $client->stopFailure = $stopFailure;

        $result = $this->service($transaction, $client)->execute($this->command());

        self::assertSame(1, $client->stopCalls);
        self::assertSame(1, $transaction->stopClaims);
        self::assertSame($expectedStatus, $result->stopStatus);
        self::assertSame($stopFailure, $result->stopFailure);
        self::assertSame(1, $client->statusCalls);
        self::assertCount(1, $transaction->stopRecords);
    }

    /** @return iterable<string, array{?PveTaskStopResult, ?PveBackupApiFailureCode, ?PveTaskStopStatus}> */
    public static function stopResultProvider(): iterable
    {
        yield 'requested' => [PveTaskStopResult::requested(), null, PveTaskStopStatus::Requested];
        yield 'ambiguous response' => [PveTaskStopResult::ambiguous(), null, PveTaskStopStatus::Ambiguous];
        yield 'thrown failure remains a single attempt' => [null, PveBackupApiFailureCode::Transport, null];
    }

    public function testUnknownDispatchAndRejectedClaimNeverIssueASecondDelete(): void
    {
        $unknown = $this->prepared(0, StopAttemptDisposition::DispatchUnknown);
        $client = new MonitoringClientFake();
        $this->service($unknown, $client)->execute($this->command());
        self::assertSame(0, $client->stopCalls);

        $rejected = $this->prepared(0, StopAttemptDisposition::ReadyToClaim);
        $rejected->allowStopClaim = false;
        $this->service($rejected, $client)->execute($this->command());
        self::assertSame(0, $client->stopCalls);
        self::assertSame(1, $rejected->stopClaims);
    }

    public function testIncompleteTaskStatusRemainsTemporarilyUnavailable(): void
    {
        $transaction = $this->prepared();
        $client = new MonitoringClientFake();
        $client->taskStatus = new PveTaskStatus(
            $this->upid(),
            PveTaskLifecycle::Stopped,
            null,
            null,
            [new PveBackupInventoryIssue(PveBackupInventoryIssueCode::InconsistentTaskStatus, '/status', 'exitstatus')],
        );

        self::assertSame(
            MonitoringTickStatus::TemporarilyUnavailable,
            $this->service($transaction, $client)->execute($this->command())->status,
        );
    }

    public function testFenceLossBetweenRemoteBoundariesPreventsFurtherPveIoAndUsesFreshTimes(): void
    {
        $transaction = $this->prepared();
        $transaction->renewResults = [true, false];
        $client = new MonitoringClientFake();
        $clock = new MonitoringSequenceClock([
            $this->now()->modify('+1 second'),
            $this->now()->modify('+40 seconds'),
        ]);

        $result = (new MonitorClaimedBackup($transaction, $client, new PveTaskStatusClassifier(), $clock))->execute($this->command());

        self::assertSame(MonitoringTickStatus::NoWork, $result->status);
        self::assertSame(0, $client->logCalls);
        self::assertSame(0, $client->statusCalls);
        self::assertSame([
            '2026-07-13T10:00:01+00:00',
            '2026-07-13T10:00:40+00:00',
        ], $transaction->renewedAt);
    }

    public function testFenceLossBeforeFirstOrStatusIoStopsAtThatBoundary(): void
    {
        $first = $this->prepared();
        $first->renewResults = [false];
        $client = new MonitoringClientFake();
        $service = new MonitorClaimedBackup($first, $client, new PveTaskStatusClassifier(), new FrozenClock($this->now()));
        self::assertSame(MonitoringTickStatus::NoWork, $service->execute($this->command())->status);
        self::assertSame(0, $client->logCalls);

        $third = $this->prepared();
        $third->renewResults = [true, true, false];
        $client = new MonitoringClientFake();
        $service = new MonitorClaimedBackup($third, $client, new PveTaskStatusClassifier(), new FrozenClock($this->now()));
        self::assertSame(MonitoringTickStatus::NoWork, $service->execute($this->command())->status);
        self::assertSame(1, $client->logCalls);
        self::assertSame(0, $client->statusCalls);
    }

    public function testMonitoringDtosRejectInvalidState(): void
    {
        foreach ([
            fn () => new MonitorClaimedBackupCommand('bad', self::id('r'), self::id('t'), 1, $this->now()),
            fn () => new MonitorClaimedBackupCommand(self::id('q'), self::id('r'), self::id('t'), 0, $this->now()),
            fn () => new MonitorClaimedBackupCommand(self::id('q'), self::id('r'), self::id('t'), 1, new DateTimeImmutable('2026-07-13T12:00:00+02:00')),
            fn () => new PreparedBackupMonitoring($this->upid(), -1, StopAttemptDisposition::NotRequested),
            static fn () => new MonitoringTickResult(MonitoringTickStatus::Running, -1),
            static fn () => new MonitoringTickResult(MonitoringTickStatus::NoWork, 1),
            static fn () => new MonitoringTickResult(
                MonitoringTickStatus::Running,
                0,
                stopStatus: PveTaskStopStatus::Requested,
                stopFailure: PveBackupApiFailureCode::Transport,
            ),
            static fn () => new MonitoringTickResult(
                MonitoringTickStatus::Running,
                0,
                statusFailure: PveBackupApiFailureCode::Transport,
            ),
            fn () => new MonitorClaimedBackup(new MonitoringTransactionFake(), new MonitoringClientFake(), new PveTaskStatusClassifier(), new FrozenClock($this->now()), 0),
            fn () => new MonitorClaimedBackup(new MonitoringTransactionFake(), new MonitoringClientFake(), new PveTaskStatusClassifier(), new FrozenClock($this->now()), 501),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid monitoring state accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function service(
        MonitoringTransactionFake $transaction,
        MonitoringClientFake $client,
        int $pageSize = 200,
    ): MonitorClaimedBackup {
        return new MonitorClaimedBackup($transaction, $client, new PveTaskStatusClassifier(), new FrozenClock($this->now()), $pageSize);
    }

    private function prepared(
        int $logOffset = 0,
        StopAttemptDisposition $stop = StopAttemptDisposition::NotRequested,
    ): MonitoringTransactionFake {
        $transaction = new MonitoringTransactionFake();
        $transaction->prepared = new PreparedBackupMonitoring($this->upid(), $logOffset, $stop);

        return $transaction;
    }

    private function command(): MonitorClaimedBackupCommand
    {
        return new MonitorClaimedBackupCommand(self::id('q'), self::id('r'), self::id('t'), 1, $this->now());
    }

    private function taskStatus(PveTaskLifecycle $lifecycle, ?string $exitStatus): PveTaskStatus
    {
        return new PveTaskStatus($this->upid(), $lifecycle, $exitStatus, null, []);
    }

    private function upid(): PveUpid
    {
        return PveUpid::parse('UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:backup@pve:');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-13T10:00:00.000000Z');
    }

    private static function id(string $byte): string
    {
        return str_repeat($byte, 16);
    }
}

final class MonitoringTransactionFake implements BackupMonitoringTransaction
{
    public ?PreparedBackupMonitoring $prepared = null;
    /** @var list<string> */ public array $events = [];
    /** @var list<MonitoringOutcome> */ public array $observations = [];
    /** @var list<array{?PveTaskStopStatus, ?PveBackupApiFailureCode}> */ public array $stopRecords = [];
    /** @var list<bool> */ public array $renewResults = [];
    /** @var list<string> */ public array $renewedAt = [];
    public int $stopClaims = 0;
    public bool $allowStopClaim = true;

    public function renew(MonitorClaimedBackupCommand $command): bool
    {
        $this->renewedAt[] = $command->now->format(DATE_ATOM);

        return [] === $this->renewResults ? true : (bool) array_shift($this->renewResults);
    }

    public function prepare(MonitorClaimedBackupCommand $command): ?PreparedBackupMonitoring
    {
        return $this->prepared;
    }

    public function claimStopAttempt(MonitorClaimedBackupCommand $command, PveUpid $upid): bool
    {
        ++$this->stopClaims;

        return $this->allowStopClaim;
    }

    public function appendLogPage(MonitorClaimedBackupCommand $command, PveUpid $upid, PveTaskLogPage $page): void
    {
        $this->events[] = 'log:'.$page->query->start.':'.$page->query->limit;
    }

    public function recordStopAttempt(
        MonitorClaimedBackupCommand $command,
        PveUpid $upid,
        ?PveTaskStopStatus $status,
        ?PveBackupApiFailureCode $failure,
    ): void {
        $this->stopRecords[] = [$status, $failure];
        $label = null !== $status ? $status->value : (null !== $failure ? $failure->value : 'none');
        $this->events[] = 'stop:'.$label;
    }

    public function recordObservation(
        MonitorClaimedBackupCommand $command,
        PveUpid $upid,
        MonitoringOutcome $outcome,
        ?string $exitStatus,
        ?PveBackupApiFailureCode $failure,
    ): void {
        $this->observations[] = $outcome;
        $failureCode = null === $failure ? 'none' : $failure->value;
        $this->events[] = 'observation:'.$outcome->value.':'.($exitStatus ?? 'none').':'.$failureCode;
    }
}

final class MonitoringSequenceClock implements Clock
{
    /** @param list<DateTimeImmutable> $times */
    public function __construct(private array $times)
    {
    }

    public function now(): DateTimeImmutable
    {
        return array_shift($this->times) ?? throw new \RuntimeException('Monitoring test clock exhausted.');
    }
}

final class MonitoringClientFake implements PveBackupClient, PveBackupClientProvider
{
    public int $logCalls = 0;
    public int $statusCalls = 0;
    public int $stopCalls = 0;
    /** @var list<PveTaskLogEntry> */ public array $logEntries = [];
    public ?PveBackupApiFailureCode $logFailure = null;
    public ?PveBackupApiFailureCode $statusFailure = null;
    public ?PveBackupApiFailureCode $stopFailure = null;
    public ?PveTaskStopResult $stopResult = null;
    public ?PveTaskStatus $taskStatus = null;
    public ?PveBackupApiFailureCode $providerFailure = null;

    public function submit(PveBackupSubmission $submission): PveBackupSubmissionResult
    {
        throw new \LogicException('Not used by monitoring.');
    }

    public function forRequest(string $requestId): PveBackupClient
    {
        if (null !== $this->providerFailure) {
            $failure = $this->providerFailure;
            $this->providerFailure = null;
            throw PveBackupApiFailure::for($failure);
        }
        return $this;
    }

    public function taskStatus(PveUpid $upid): PveTaskStatus
    {
        ++$this->statusCalls;
        if (null !== $this->statusFailure) {
            throw PveBackupApiFailure::for($this->statusFailure);
        }

        return $this->taskStatus ?? new PveTaskStatus($upid, PveTaskLifecycle::Running, null, null, []);
    }

    public function taskLog(PveUpid $upid, PveTaskLogQuery $query): PveTaskLogPage
    {
        ++$this->logCalls;
        if (null !== $this->logFailure) {
            throw PveBackupApiFailure::for($this->logFailure);
        }

        return new PveTaskLogPage($query, $this->logEntries);
    }

    public function stopTask(PveUpid $upid): PveTaskStopResult
    {
        ++$this->stopCalls;
        if (null !== $this->stopFailure) {
            throw PveBackupApiFailure::for($this->stopFailure);
        }

        return $this->stopResult ?? PveTaskStopResult::requested();
    }

    public function taskPage(string $node, PveTaskQuery $query): PveTaskPage
    {
        throw new \LogicException('Not used by monitoring.');
    }

    public function calls(): int
    {
        return $this->logCalls + $this->statusCalls + $this->stopCalls;
    }
}
