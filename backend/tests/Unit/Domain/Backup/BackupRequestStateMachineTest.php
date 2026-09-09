<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Backup;

use App\Domain\Backup\BackupRequest;
use App\Domain\Backup\BackupRequestId;
use App\Domain\Backup\BackupRequestState;
use App\Domain\Backup\BackupRun;
use App\Domain\Backup\BackupRunId;
use App\Domain\Backup\ClaimAuthority;
use App\Domain\Backup\ClaimFence;
use App\Domain\Backup\ClaimLease;
use App\Domain\Backup\ClaimToken;
use App\Domain\Backup\MonitoringOutcome;
use App\Domain\Backup\RecoveryOutcome;
use App\Domain\Backup\RetryDisposition;
use App\Domain\Backup\SubmissionOutcome;
use App\Domain\Backup\TaskUpid;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\Priority;
use App\Domain\Scheduler\ReasonPriority;
use App\Domain\Scheduler\RequestOrigin;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class BackupRequestStateMachineTest extends TestCase
{
    public function testClaimSubmissionMonitoringAndControlledRetryPreserveTheContract(): void
    {
        $request = $this->initial();
        $authority = $this->authority(1, 'a');
        $lease = $this->lease($authority, '10:00:00.000000', '10:01:00.000000');
        $claimed = $request->claim($lease, $this->at('10:00:00.000000'));
        self::assertSame(BackupRequestState::Leased, $claimed->state);
        self::assertSame(1, $claimed->lastFence?->value);

        $renewed = $claimed->renew(
            $authority,
            $this->at('10:00:30.000000'),
            $this->at('10:02:00.000000'),
        );
        $runId = new BackupRunId(str_repeat('r', 16));
        $starting = $renewed->beginRun($authority, $this->at('10:00:31.000000'), $runId);
        $run = BackupRun::awaitingSubmission($runId, $request->id, $authority);
        self::assertSame(BackupRequestState::Starting, $starting->synchronizeRun(
            $authority,
            $this->at('10:00:31.000000'),
            $run,
        )->state);

        $runningRun = $run->applySubmission($authority, SubmissionOutcome::accepted($this->upid()));
        $running = $starting->synchronizeRun($authority, $this->at('10:00:32.000000'), $runningRun);
        self::assertSame(BackupRequestState::Running, $running->state);
        $failedRun = $runningRun->observe($authority, MonitoringOutcome::Failed);
        $failed = $running->synchronizeRun($authority, $this->at('10:00:33.000000'), $failedRun);
        self::assertSame(BackupRequestState::Failed, $failed->state);
        self::assertNull($failed->lease);
        self::assertSame(RetryDisposition::ControlledAllowed, $failed->retryDisposition);

        $retry = BackupRequest::controlledRetry(new BackupRequestId(str_repeat('n', 16)), $failed);
        self::assertSame(RequestOrigin::Retry, $retry->origin);
        self::assertSame(2, $retry->attempt);
        self::assertSame($request->rootRequestId, $retry->rootRequestId);
        self::assertSame($request->reasonPriority, $retry->reasonPriority);
        self::assertSame(BackupRequestState::Pending, $retry->state);
    }

    public function testAmbiguousSubmissionBecomesReconcileRequiredThenTerminalUnknownAndNeverRetryable(): void
    {
        [$starting, $run, $authority] = $this->startingRequest();
        $ambiguous = $run->applySubmission($authority, SubmissionOutcome::ambiguous());
        $reconcile = $starting->synchronizeRun($authority, $this->at('10:00:02.000000'), $ambiguous);
        self::assertSame(BackupRequestState::ReconcileRequired, $reconcile->state);
        $unknownRun = $ambiguous->reconcile($authority, RecoveryOutcome::inconclusive());
        $unknown = $reconcile->synchronizeRun($authority, $this->at('10:00:03.000000'), $unknownRun);
        self::assertSame(BackupRequestState::Unknown, $unknown->state);
        self::assertTrue($unknown->state->isTerminal());
        self::assertSame(RetryDisposition::ForbiddenAmbiguous, $unknown->retryDisposition);

        $this->assertInvalid(fn () => BackupRequest::controlledRetry(
            new BackupRequestId(str_repeat('n', 16)),
            $unknown,
        ));

        [$anotherStarting, $anotherRun, $anotherAuthority] = $this->startingRequest();
        $anotherAmbiguous = $anotherRun->applySubmission($anotherAuthority, SubmissionOutcome::ambiguous());
        $anotherReconciling = $anotherStarting->synchronizeRun(
            $anotherAuthority,
            $this->at('10:00:02.000000'),
            $anotherAmbiguous,
        );
        $provenAbsent = $anotherAmbiguous->reconcile($anotherAuthority, RecoveryOutcome::provenNotStarted());
        $unknownButForbidden = $anotherReconciling->synchronizeRun(
            $anotherAuthority,
            $this->at('10:00:03.000000'),
            $provenAbsent,
        );
        self::assertSame(BackupRequestState::Unknown, $unknownButForbidden->state);
        self::assertSame(RetryDisposition::ForbiddenAmbiguous, $unknownButForbidden->retryDisposition);
        $this->assertInvalid(fn () => BackupRequest::controlledRetry(
            new BackupRequestId(str_repeat('p', 16)),
            $unknownButForbidden,
        ));
    }

    public function testAcceptedRunCancellationAndSuccessAreReflectedWithoutLosingUpidProvenance(): void
    {
        foreach ([
            [MonitoringOutcome::Succeeded, BackupRequestState::Succeeded],
            [MonitoringOutcome::Cancelled, BackupRequestState::Cancelled],
        ] as [$outcome, $expected]) {
            [$starting, $run, $authority] = $this->startingRequest();
            $running = $run->applySubmission($authority, SubmissionOutcome::accepted($this->upid()));
            $request = $starting->synchronizeRun($authority, $this->at('10:00:02.000000'), $running);
            $terminal = $running->observe($authority, $outcome);
            $reflected = $request->synchronizeRun($authority, $this->at('10:00:03.000000'), $terminal);
            self::assertSame($expected, $reflected->state);
            self::assertSame(RetryDisposition::ControlledAllowed, $reflected->retryDisposition);
        }
    }

    public function testReleaseDeferReclaimAndCancelEdgesAreExplicit(): void
    {
        $request = $this->initial();
        $first = $this->authority(1, 'a');
        $claimed = $request->claim(
            $this->lease($first, '10:00:00.000000', '10:01:00.000000'),
            $this->at('10:00:00.000000'),
        );
        $released = $claimed->release($first, $this->at('10:00:01.000000'));
        self::assertSame(BackupRequestState::Pending, $released->state);
        self::assertSame(1, $released->lastFence?->value);
        $second = $this->authority(2, 'b');
        $reclaimed = $released->claim(
            $this->lease($second, '10:00:02.000000', '10:01:02.000000'),
            $this->at('10:00:02.000000'),
        );
        $deferred = $reclaimed->defer($second, $this->at('10:00:03.000000'));
        self::assertSame(BackupRequestState::RetryWait, $deferred->state);
        $third = $this->authority(3, 'c');
        self::assertSame(BackupRequestState::Leased, $deferred->claim(
            $this->lease($third, '10:00:04.000000', '10:01:04.000000'),
            $this->at('10:00:04.000000'),
        )->state);
        self::assertSame(BackupRequestState::Cancelled, $deferred->cancelUnclaimed()->state);
        self::assertSame(BackupRequestState::Cancelled, $this->initial()->cancelUnclaimed()->state);

        $leased = $this->initial()->claim(
            $this->lease($first, '10:00:00.000000', '10:01:00.000000'),
            $this->at('10:00:00.000000'),
        );
        self::assertSame(
            BackupRequestState::Cancelled,
            $leased->cancelLeased($first, $this->at('10:00:01.000000'))->state,
        );
    }

    public function testExpiredLeaseNeedsStrictlyHigherFenceAndStaleWorkerCannotMutate(): void
    {
        $old = $this->authority(1, 'a');
        $claimed = $this->initial()->claim(
            $this->lease($old, '10:00:00.000000', '10:01:00.000000'),
            $this->at('10:00:00.000000'),
        );
        $new = $this->authority(2, 'b');
        $taken = $claimed->takeover(
            $this->lease($new, '10:01:00.000000', '10:02:00.000000'),
            $this->at('10:01:00.000000'),
        );
        self::assertSame($new, $taken->lease?->authority);
        self::assertSame(2, $taken->lastFence?->value);
        $this->assertInvalid(fn () => $taken->release($old, $this->at('10:01:01.000000')));

        foreach ([
            fn () => $claimed->takeover(
                $this->lease($new, '10:00:30.000000', '10:02:00.000000'),
                $this->at('10:00:30.000000'),
            ),
            fn () => $claimed->takeover(
                $this->lease($old, '10:01:00.000000', '10:02:00.000000'),
                $this->at('10:01:00.000000'),
            ),
            fn () => $claimed->release($old, $this->at('10:01:00.000000')),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }
    }

    public function testInvalidClaimsOwnershipRunIdentityCancelAndRetryEdgesFailClosed(): void
    {
        $request = $this->initial();
        $authority = $this->authority(1, 'a');
        $lease = $this->lease($authority, '10:00:00.000000', '10:01:00.000000');
        $claimed = $request->claim($lease, $this->at('10:00:00.000000'));
        $runId = new BackupRunId(str_repeat('r', 16));
        $starting = $claimed->beginRun($authority, $this->at('10:00:01.000000'), $runId);
        $run = BackupRun::awaitingSubmission($runId, $request->id, $authority);

        foreach ([
            fn () => BackupRequest::initial($request->id, RequestOrigin::Retry, $request->reasonPriority),
            fn () => BackupRequest::controlledRetry($request->id, $request),
            fn () => $claimed->claim($lease, $this->at('10:00:01.000000')),
            fn () => $request->claim($lease, $this->at('10:01:00.000000')),
            fn () => $request->renew($authority, $this->at('10:00:00.000000'), $this->at('10:02:00.000000')),
            fn () => $starting->release($authority, $this->at('10:00:02.000000')),
            fn () => $starting->defer($authority, $this->at('10:00:02.000000')),
            fn () => $starting->cancelLeased($authority, $this->at('10:00:02.000000')),
            fn () => $starting->cancelUnclaimed(),
            fn () => $starting->synchronizeRun(
                $authority,
                $this->at('10:00:02.000000'),
                BackupRun::awaitingSubmission(
                    new BackupRunId(str_repeat('x', 16)),
                    $request->id,
                    $authority,
                ),
            ),
            fn () => $starting->synchronizeRun(
                $authority,
                $this->at('10:00:02.000000'),
                BackupRun::awaitingSubmission(
                    $runId,
                    new BackupRequestId(str_repeat('x', 16)),
                    $authority,
                ),
            ),
            fn () => $starting->synchronizeRun(
                $authority,
                $this->at('10:00:02.000000'),
                $run->takeover($this->authority(2, 'b')),
            ),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }
    }

    public function testRestoredRequestsRejectInvalidAttemptLeaseRunAndFenceCombinations(): void
    {
        $request = $this->initial();
        $authority = $this->authority(1, 'a');
        $lease = $this->lease($authority, '10:00:00.000000', '10:01:00.000000');
        $runId = new BackupRunId(str_repeat('r', 16));
        $restored = BackupRequest::restore(
            $request->id,
            $request->rootRequestId,
            1,
            $request->origin,
            $request->reasonPriority,
            BackupRequestState::Leased,
            $lease,
            $authority->fence,
            null,
            RetryDisposition::NotApplicable,
        );
        self::assertSame(BackupRequestState::Leased, $restored->state);

        foreach ([
            fn () => BackupRequest::restore(
                $request->id, $request->rootRequestId, 0, $request->origin, $request->reasonPriority,
                BackupRequestState::Pending, null, null, null, RetryDisposition::NotApplicable,
            ),
            fn () => BackupRequest::restore(
                $request->id, $request->rootRequestId, 1, $request->origin, $request->reasonPriority,
                BackupRequestState::Leased, null, null, null, RetryDisposition::NotApplicable,
            ),
            fn () => BackupRequest::restore(
                $request->id, $request->rootRequestId, 1, $request->origin, $request->reasonPriority,
                BackupRequestState::Pending, $lease, $authority->fence, null, RetryDisposition::NotApplicable,
            ),
            fn () => BackupRequest::restore(
                $request->id, $request->rootRequestId, 1, $request->origin, $request->reasonPriority,
                BackupRequestState::Starting, $lease, $authority->fence, null, RetryDisposition::NotApplicable,
            ),
            fn () => BackupRequest::restore(
                $request->id, $request->rootRequestId, 1, $request->origin, $request->reasonPriority,
                BackupRequestState::Pending, null, null, $runId, RetryDisposition::NotApplicable,
            ),
            fn () => BackupRequest::restore(
                $request->id, $request->rootRequestId, 1, $request->origin, $request->reasonPriority,
                BackupRequestState::Leased, $lease, null, null, RetryDisposition::NotApplicable,
            ),
            fn () => BackupRequest::restore(
                $request->id, $request->rootRequestId, 1, $request->origin, $request->reasonPriority,
                BackupRequestState::Leased, $lease, new ClaimFence(2), null, RetryDisposition::NotApplicable,
            ),
        ] as $invalid) {
            $this->assertInvalid($invalid);
        }

        $maximum = BackupRequest::restore(
            $request->id,
            $request->rootRequestId,
            PHP_INT_MAX,
            $request->origin,
            $request->reasonPriority,
            BackupRequestState::Failed,
            null,
            null,
            $runId,
            RetryDisposition::ControlledAllowed,
        );
        try {
            BackupRequest::controlledRetry(new BackupRequestId(str_repeat('n', 16)), $maximum);
            self::fail('An overflowing retry attempt was accepted.');
        } catch (OverflowException) {
            self::addToAssertionCount(1);
        }
        $failed = BackupRequest::restore(
            $request->id,
            $request->rootRequestId,
            1,
            $request->origin,
            $request->reasonPriority,
            BackupRequestState::Failed,
            null,
            null,
            $runId,
            RetryDisposition::ControlledAllowed,
        );
        $this->assertInvalid(fn () => BackupRequest::controlledRetry($request->id, $failed));

        $released = $restored->release($authority, $this->at('10:00:01.000000'));
        $this->assertInvalid(fn () => $released->claim(
            $this->lease($authority, '10:00:02.000000', '10:01:02.000000'),
            $this->at('10:00:02.000000'),
        ));
    }

    /** @return array{BackupRequest, BackupRun, ClaimAuthority} */
    private function startingRequest(): array
    {
        $request = $this->initial();
        $authority = $this->authority(1, 'a');
        $claimed = $request->claim(
            $this->lease($authority, '10:00:00.000000', '10:01:00.000000'),
            $this->at('10:00:00.000000'),
        );
        $runId = new BackupRunId(str_repeat('r', 16));

        return [
            $claimed->beginRun($authority, $this->at('10:00:01.000000'), $runId),
            BackupRun::awaitingSubmission($runId, $request->id, $authority),
            $authority,
        ];
    }

    private function initial(): BackupRequest
    {
        return BackupRequest::initial(
            new BackupRequestId(str_repeat('q', 16)),
            RequestOrigin::Automatic,
            new ReasonPriority(BackupReason::NeverBackedUp, Priority::NeverBackedUp),
        );
    }

    private function lease(ClaimAuthority $authority, string $issued, string $expires): ClaimLease
    {
        return new ClaimLease($authority, $this->at($issued), $this->at($expires));
    }

    private function authority(int $fence, string $byte): ClaimAuthority
    {
        return new ClaimAuthority(new ClaimToken(str_repeat($byte, 16)), new ClaimFence($fence));
    }

    private function upid(): TaskUpid
    {
        return new TaskUpid('UPID:node:00000001:00000002:00000003:vzdump:100:root@pam:');
    }

    private function at(string $time): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            '2026-07-12 '.$time,
            new DateTimeZone('UTC'),
        ) ?: throw new InvalidArgumentException('Invalid test time.');
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            self::fail('An illegal backup-request transition was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
