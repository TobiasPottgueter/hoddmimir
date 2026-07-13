<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Backup;

use App\Domain\Backup\BackupRequestId;
use App\Domain\Backup\BackupRun;
use App\Domain\Backup\BackupRunId;
use App\Domain\Backup\BackupRunState;
use App\Domain\Backup\ClaimAuthority;
use App\Domain\Backup\ClaimFence;
use App\Domain\Backup\ClaimToken;
use App\Domain\Backup\MonitoringOutcome;
use App\Domain\Backup\RecoveryOutcome;
use App\Domain\Backup\SubmissionOutcome;
use App\Domain\Backup\SubmissionProvenance;
use App\Domain\Backup\TaskUpid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupRunStateMachineTest extends TestCase
{
    public function testAcceptedSubmissionRetainsExactlyOneUpidThroughMonitoringAndCancel(): void
    {
        $authority = $this->authority(1, 'a');
        $upid = $this->upid();
        $running = $this->newRun($authority)->applySubmission($authority, SubmissionOutcome::accepted($upid));
        self::assertSame(BackupRunState::Running, $running->state);
        self::assertSame($upid, $running->upid);
        self::assertSame(SubmissionProvenance::Accepted, $running->submissionProvenance);
        self::assertSame($running, $running->observe($authority, MonitoringOutcome::Running));
        self::assertSame($running, $running->observe($authority, MonitoringOutcome::TemporarilyUnavailable));

        $cancelling = $running->requestCancel($authority);
        self::assertSame(BackupRunState::CancelRequested, $cancelling->state);
        self::assertSame($upid, $cancelling->upid);
        self::assertSame($cancelling, $cancelling->observe($authority, MonitoringOutcome::Running));
        self::assertSame(BackupRunState::Cancelled, $cancelling->observe($authority, MonitoringOutcome::Cancelled)->state);
        self::assertSame(BackupRunState::Succeeded, $cancelling->observe($authority, MonitoringOutcome::Succeeded)->state);
    }

    public function testDefinitiveSubmissionRejectionFailsWithoutUpid(): void
    {
        $authority = $this->authority(1, 'a');
        $failed = $this->newRun($authority)->applySubmission($authority, SubmissionOutcome::rejected());
        self::assertSame(BackupRunState::Failed, $failed->state);
        self::assertNull($failed->upid);
        self::assertSame(SubmissionProvenance::DefinitiveRejection, $failed->submissionProvenance);
    }

    /** @return iterable<string, array{RecoveryOutcome, BackupRunState, bool}> */
    public static function recoveryOutcomes(): iterable
    {
        yield 'matched task' => [RecoveryOutcome::matched(self::staticUpid()), BackupRunState::Running, true];
        yield 'proven absent remains unknown' => [RecoveryOutcome::provenNotStarted(), BackupRunState::Unknown, false];
        yield 'multiple matches remain unknown' => [RecoveryOutcome::multipleMatches(), BackupRunState::Unknown, false];
        yield 'still ambiguous' => [RecoveryOutcome::inconclusive(), BackupRunState::Unknown, false];
    }

    #[DataProvider('recoveryOutcomes')]
    public function testAmbiguousSubmissionRequiresRecoveryAndNeverQueuesAgain(
        RecoveryOutcome $outcome,
        BackupRunState $expected,
        bool $hasUpid,
    ): void {
        $authority = $this->authority(1, 'a');
        $ambiguous = $this->newRun($authority)->applySubmission($authority, SubmissionOutcome::ambiguous());
        self::assertSame(BackupRunState::ReconcileRequired, $ambiguous->state);
        self::assertNull($ambiguous->upid);
        $recovered = $ambiguous->reconcile($authority, $outcome);
        self::assertSame($expected, $recovered->state);
        self::assertSame($hasUpid, null !== $recovered->upid);
        self::assertSame(SubmissionProvenance::Ambiguous, $recovered->submissionProvenance);
    }

    #[DataProvider('terminalMonitoringOutcomes')]
    public function testMonitoringMapsOnlyObservedTaskTerminalState(
        MonitoringOutcome $outcome,
        BackupRunState $expected,
    ): void {
        $authority = $this->authority(1, 'a');
        $running = $this->newRun($authority)->applySubmission($authority, SubmissionOutcome::accepted($this->upid()));
        self::assertSame($expected, $running->observe($authority, $outcome)->state);
    }

    /** @return iterable<string, array{MonitoringOutcome, BackupRunState}> */
    public static function terminalMonitoringOutcomes(): iterable
    {
        yield 'success' => [MonitoringOutcome::Succeeded, BackupRunState::Succeeded];
        yield 'failure' => [MonitoringOutcome::Failed, BackupRunState::Failed];
        yield 'cancelled' => [MonitoringOutcome::Cancelled, BackupRunState::Cancelled];
    }

    public function testHigherFenceCanRecoverNonterminalRunAndStaleWorkerLosesAuthority(): void
    {
        $old = $this->authority(1, 'a');
        $new = $this->authority(2, 'b');
        $running = $this->newRun($old)->applySubmission($old, SubmissionOutcome::accepted($this->upid()));
        $taken = $running->takeover($new);
        self::assertSame($new, $taken->authority);
        self::assertSame(BackupRunState::Running, $taken->observe($new, MonitoringOutcome::Running)->state);

        foreach ([
            fn () => $taken->observe($old, MonitoringOutcome::Succeeded),
            fn () => $taken->takeover($this->authority(2, 'c')),
            fn () => $taken->takeover($this->authority(1, 'c')),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }
    }

    public function testIllegalEdgesAndTerminalRegressionAreRejected(): void
    {
        $authority = $this->authority(1, 'a');
        $awaiting = $this->newRun($authority);
        $running = $awaiting->applySubmission($authority, SubmissionOutcome::accepted($this->upid()));
        $terminal = $running->observe($authority, MonitoringOutcome::Succeeded);
        foreach ([
            fn () => $awaiting->observe($authority, MonitoringOutcome::Running),
            fn () => $awaiting->requestCancel($authority),
            fn () => $awaiting->reconcile($authority, RecoveryOutcome::inconclusive()),
            fn () => $running->applySubmission($authority, SubmissionOutcome::ambiguous()),
            fn () => $running->reconcile($authority, RecoveryOutcome::inconclusive()),
            fn () => $terminal->observe($authority, MonitoringOutcome::Running),
            fn () => $terminal->requestCancel($authority),
            fn () => $terminal->takeover($this->authority(2, 'b')),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }
    }

    public function testRestoredRunsRejectEveryInconsistentStateUpidAndProvenanceCombination(): void
    {
        $authority = $this->authority(1, 'a');
        $id = new BackupRunId(str_repeat('r', 16));
        $requestId = new BackupRequestId(str_repeat('q', 16));
        $upid = $this->upid();
        $restored = BackupRun::restore(
            $id,
            $requestId,
            $authority,
            BackupRunState::Running,
            $upid,
            SubmissionProvenance::Accepted,
        );
        self::assertSame(BackupRunState::Running, $restored->state);

        foreach ([
            fn () => BackupRun::restore(
                $id, $requestId, $authority, BackupRunState::Running, null, SubmissionProvenance::Accepted,
            ),
            fn () => BackupRun::restore(
                $id, $requestId, $authority, BackupRunState::AwaitingSubmission, $upid, SubmissionProvenance::Accepted,
            ),
            fn () => BackupRun::restore(
                $id, $requestId, $authority, BackupRunState::Failed, null, SubmissionProvenance::Accepted,
            ),
            fn () => BackupRun::restore(
                $id, $requestId, $authority, BackupRunState::Failed, $upid, SubmissionProvenance::DefinitiveRejection,
            ),
            fn () => BackupRun::restore(
                $id, $requestId, $authority, BackupRunState::AwaitingSubmission, null, SubmissionProvenance::Ambiguous,
            ),
            fn () => BackupRun::restore(
                $id, $requestId, $authority, BackupRunState::ReconcileRequired, null, SubmissionProvenance::NotSubmitted,
            ),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            self::fail('An illegal backup-run transition was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    private function newRun(ClaimAuthority $authority): BackupRun
    {
        return BackupRun::awaitingSubmission(
            new BackupRunId(str_repeat('r', 16)),
            new BackupRequestId(str_repeat('q', 16)),
            $authority,
        );
    }

    private function authority(int $fence, string $byte): ClaimAuthority
    {
        return new ClaimAuthority(new ClaimToken(str_repeat($byte, 16)), new ClaimFence($fence));
    }

    private function upid(): TaskUpid
    {
        return self::staticUpid();
    }

    private static function staticUpid(): TaskUpid
    {
        return new TaskUpid('UPID:node:00000001:00000002:00000003:vzdump:100:root@pam:');
    }
}
