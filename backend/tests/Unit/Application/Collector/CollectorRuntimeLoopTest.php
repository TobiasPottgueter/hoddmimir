<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Collector;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorClaimDecision;
use App\Application\Collector\CollectorCycleCoordinator;
use App\Application\Collector\CollectorCycleResult;
use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorCycleTokenFactory;
use App\Application\Collector\CollectorHeartbeatStore;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorRuntimeLoop;
use App\Application\Collector\CollectorRuntimeWaiter;
use App\Application\Collector\CollectorScheduleSnapshot;
use App\Application\Collector\CollectorScheduleStore;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorWorkerIdentity;
use App\Application\Collector\CollectorWorkerRunCode;
use App\Application\Collector\CollectorWorkerStatus;
use App\Application\Collector\GridSchedule;
use App\Application\Collector\RunCollectorCycle;
use App\Application\Collector\RunCollectorWorker;
use App\Application\Collector\StopRequested;
use App\Application\Readiness\ReadinessAggregator;
use App\Application\Readiness\ReadinessCheck;
use App\Application\Readiness\ReadinessCheckResult;
use App\Application\Worker\MonotonicClock;
use App\Application\Worker\WorkerReadinessProbe;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CollectorRuntimeLoopTest extends TestCase
{
    public const string NOW = '2026-07-11T10:00:00.000000Z';

    public function testRunWorkerUsesOneStableIdentityAndFreshTokenForEveryClaimAttempt(): void
    {
        $schedule = new RuntimeScheduleStore([
            $this->waiting('+120 seconds'),
            $this->waiting('+120 seconds'),
        ]);
        $stop = new RuntimeStopRequested([false, false, false, false, true]);
        $tokens = new RuntimeTokenFactory();
        $identity = new RuntimeIdentity();
        $result = (new RunCollectorWorker(
            $identity,
            $this->loop($schedule, $stop, new RuntimeCycleRunner([]), $tokens),
        ))->run(false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame(1, $identity->reads);
        self::assertCount(2, $schedule->claimTokens);
        self::assertNotSame($schedule->claimTokens[0], $schedule->claimTokens[1]);
        self::assertSame(2, $tokens->generated);
    }

    public function testOnceIsDueOnlyAndDoesNotWaitOrExecuteWhenNoCycleIsDue(): void
    {
        $schedule = new RuntimeScheduleStore([$this->waiting('+120 seconds')]);
        $waiter = new RuntimeWaiter();
        $runner = new RuntimeCycleRunner([]);

        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false], maximumCalls: 1),
            $runner,
            waiter: $waiter,
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::NoCycleDue, $result->code);
        self::assertSame(0, $result->exitCode());
        self::assertSame([], $waiter->seconds);
        self::assertSame(0, $runner->calls);
        self::assertSame(1, $schedule->bootstraps);
    }

    /** @return iterable<string, array{CollectorCycleStatus, CollectorWorkerRunCode, int}> */
    public static function onceOutcomes(): iterable
    {
        yield 'succeeded' => [CollectorCycleStatus::Succeeded, CollectorWorkerRunCode::CycleSucceeded, 0];
        yield 'partial' => [CollectorCycleStatus::Partial, CollectorWorkerRunCode::CyclePartial, 1];
        yield 'failed' => [CollectorCycleStatus::Failed, CollectorWorkerRunCode::CycleFailed, 1];
    }

    #[DataProvider('onceOutcomes')]
    public function testOnceReturnsHonestCycleOutcome(
        CollectorCycleStatus $status,
        CollectorWorkerRunCode $code,
        int $exitCode,
    ): void {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);

        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested(array_fill(0, 8, false)),
            new RuntimeCycleRunner([$status]),
        )->run($this->worker(), true);

        self::assertSame($code, $result->code);
        self::assertSame($exitCode, $result->exitCode());
        self::assertSame([$status], $schedule->finalized);
        self::assertSame(2, $schedule->renewals);
    }

    public function testContinuousLoopKeepsRunningAfterPartialAndFailedCycles(): void
    {
        $schedule = new RuntimeScheduleStore([
            $this->claimed('a'),
            $this->claimed('b'),
        ]);
        $runner = new RuntimeCycleRunner([
            CollectorCycleStatus::Partial,
            CollectorCycleStatus::Failed,
        ]);
        $stop = new RuntimeStopRequested([
            false, false, false, false, false, false,
            false, false, false, false, false, true,
        ]);

        $result = $this->loop($schedule, $stop, $runner)->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([CollectorCycleStatus::Partial, CollectorCycleStatus::Cancelled], $schedule->finalized);
        self::assertSame(2, $runner->calls);
    }

    public function testContinuousSuccessfulCycleReturnsToTheLoopUntilIdleShutdown(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false, false, false, false, false, false, true]),
            new RuntimeCycleRunner([CollectorCycleStatus::Succeeded]),
        )->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([CollectorCycleStatus::Succeeded], $schedule->finalized);
    }

    public function testContinuousFailedCycleReturnsToTheLoopUntilIdleShutdown(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false, false, false, false, false, false, true]),
            new RuntimeCycleRunner([CollectorCycleStatus::Failed]),
        )->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([CollectorCycleStatus::Failed], $schedule->finalized);
    }

    public function testIdleWaitIsCeiledBoundedAndRefreshesHeartbeatOnEveryAttempt(): void
    {
        $schedule = new RuntimeScheduleStore([
            $this->waiting('-1 second'),
            $this->waiting('+90 seconds'),
        ]);
        $waiter = new RuntimeWaiter();
        $stop = new RuntimeStopRequested([false, false, false, false, true]);
        $heartbeats = new RuntimeHeartbeatStore();

        $result = $this->loop(
            $schedule,
            $stop,
            new RuntimeCycleRunner([]),
            waiter: $waiter,
            heartbeats: $heartbeats,
        )->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([1, 30], $waiter->seconds);
        self::assertSame(
            [CollectorWorkerStatus::Ready, CollectorWorkerStatus::Ready, CollectorWorkerStatus::Ready, CollectorWorkerStatus::Stopping],
            $heartbeats->statuses,
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function exactIdleWaitBoundaries(): iterable
    {
        yield 'same instant still waits one second' => ['+0 microseconds', 1];
        yield 'one microsecond is ceiled to one second' => ['+1 microsecond', 1];
        yield 'one exact second remains one second' => ['+1 second', 1];
        yield 'one microsecond over maximum is capped' => ['+30 seconds +1 microsecond', 30];
    }

    #[DataProvider('exactIdleWaitBoundaries')]
    public function testIdleWaitRoundingAndCapBoundariesAreExact(string $retryModifier, int $expectedSeconds): void
    {
        $schedule = new RuntimeScheduleStore([$this->waiting($retryModifier)]);
        $waiter = new RuntimeWaiter();

        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false, false, true]),
            new RuntimeCycleRunner([]),
            waiter: $waiter,
        )->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([$expectedSeconds], $waiter->seconds);
    }

    public function testReadinessFailsClosedBeforeScheduleInitialization(): void
    {
        $schedule = new RuntimeScheduleStore([]);
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([], maximumCalls: 1),
            new RuntimeCycleRunner([]),
            readiness: [ReadinessCheckResult::unavailable('database_schema', 'database_unavailable')],
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::ReadinessUnavailable, $result->code);
        self::assertSame(1, $result->exitCode());
        self::assertNotNull($result->readiness);
        self::assertSame(0, $schedule->bootstraps);
        self::assertSame('readiness_unavailable', $result->toArray()['code']);
    }

    public function testDaemonRetriesTemporaryReadinessFailureWithoutAnotherClaim(): void
    {
        $schedule = new RuntimeScheduleStore([$this->waiting('+5 seconds')]);
        $heartbeats = new RuntimeHeartbeatStore();
        $waiter = new RuntimeWaiter();
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false, false, false, false, false, false, false, true]),
            new RuntimeCycleRunner([]),
            readiness: [
                ReadinessCheckResult::ready('database_schema'),
                ReadinessCheckResult::unavailable('database_schema', 'database_unavailable'),
            ],
            heartbeats: $heartbeats,
            waiter: $waiter,
        )->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertCount(1, $schedule->claimTokens);
        self::assertSame([5, 30, 30, 30, 30, 30], $waiter->seconds);
        self::assertSame(5, count(array_filter(
            $heartbeats->statuses,
            static fn (CollectorWorkerStatus $status): bool => CollectorWorkerStatus::Degraded === $status,
        )));
        self::assertSame(CollectorWorkerStatus::Stopping, $heartbeats->statuses[count($heartbeats->statuses) - 1]);
    }

    public function testDaemonRetriesInitialReadinessFailureWithABoundedSignalAwareWait(): void
    {
        $schedule = new RuntimeScheduleStore([]);
        $waiter = new RuntimeWaiter();
        $heartbeats = new RuntimeHeartbeatStore();
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false, true], maximumCalls: 2),
            new RuntimeCycleRunner([]),
            readiness: [ReadinessCheckResult::unavailable('database_schema', 'database_unavailable')],
            waiter: $waiter,
            heartbeats: $heartbeats,
        )->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([30], $waiter->seconds);
        self::assertSame(0, $schedule->bootstraps);
        self::assertSame([], $schedule->claimTokens);
        self::assertSame([CollectorWorkerStatus::Stopping], $heartbeats->statuses);
    }

    public function testIdentityFailureReturnsAStableRedactedRuntimeCode(): void
    {
        $identity = new RuntimeIdentity(new RuntimeException('identity path secret'));
        $result = (new RunCollectorWorker(
            $identity,
            $this->loop(
                new RuntimeScheduleStore([]),
                new RuntimeStopRequested([]),
                new RuntimeCycleRunner([]),
            ),
        ))->run(true);

        self::assertSame(CollectorWorkerRunCode::RuntimeFailed, $result->code);
        self::assertStringNotContainsString('secret', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testIdleShutdownStopsWithoutClaiming(): void
    {
        $schedule = new RuntimeScheduleStore([]);
        $heartbeats = new RuntimeHeartbeatStore();
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([true], maximumCalls: 1),
            new RuntimeCycleRunner([]),
            heartbeats: $heartbeats,
        )->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([], $schedule->claimTokens);
        self::assertSame([CollectorWorkerStatus::Ready, CollectorWorkerStatus::Stopping], $heartbeats->statuses);
    }

    public function testShutdownObservedAfterANotDueClaimDecisionStopsBeforeWaiting(): void
    {
        $schedule = new RuntimeScheduleStore([$this->waiting('+2 minutes')]);
        $waiter = new RuntimeWaiter();
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false, true]),
            new RuntimeCycleRunner([]),
            waiter: $waiter,
        )->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([], $waiter->seconds);
    }

    /** @return iterable<string, array{list<bool>, int}> */
    public static function claimedShutdownBoundaries(): iterable
    {
        yield 'before first renew' => [[false, true], 0];
        yield 'after first renew' => [[false, false, true], 1];
        yield 'before final renew' => [[false, false, false, true], 1];
        yield 'after final renew' => [[false, false, false, false, true], 2];
        yield 'after work checkpoint' => [[false, false, false, false, false, true], 2];
    }

    /** @param list<bool> $stopSequence */
    #[DataProvider('claimedShutdownBoundaries')]
    public function testClaimedShutdownAlwaysFinalizesCancelledAtASafeBoundary(
        array $stopSequence,
        int $renewals,
    ): void {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested($stopSequence),
            new RuntimeCycleRunner([CollectorCycleStatus::Succeeded]),
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([CollectorCycleStatus::Cancelled], $schedule->finalized);
        self::assertSame($renewals, $schedule->renewals);
    }

    public function testLeaseOwnershipLossNeverFinalizesOrOverwritesTheLastBusyHeartbeat(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $schedule->renewFailure = new CollectorLeaseOwnershipLost('lost');
        $heartbeats = new RuntimeHeartbeatStore();

        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false, false]),
            new RuntimeCycleRunner([CollectorCycleStatus::Succeeded]),
            heartbeats: $heartbeats,
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::LeaseOwnershipLost, $result->code);
        self::assertSame([], $schedule->finalized);
        self::assertSame(CollectorWorkerStatus::Busy, $heartbeats->statuses[count($heartbeats->statuses) - 1]);
    }

    public function testOwnershipLossFromPostClaimHeartbeatCleanupReturnsWithoutIdleOverwrite(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $schedule->finalizeFailure = new CollectorLeaseOwnershipLost('lost');
        $heartbeats = new RuntimeHeartbeatStore([CollectorWorkerStatus::Busy]);
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false]),
            new RuntimeCycleRunner([]),
            heartbeats: $heartbeats,
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::LeaseOwnershipLost, $result->code);
        self::assertSame([CollectorWorkerStatus::Ready], $heartbeats->statuses);
        self::assertSame(1, $schedule->finalizeAttempts);
    }

    public function testUnexpectedFailureAfterInitializationStopsIdleBestEffort(): void
    {
        $heartbeats = new RuntimeHeartbeatStore();
        $tokens = new RuntimeTokenFactory(new RuntimeException('token secret'));
        $result = $this->loop(
            new RuntimeScheduleStore([]),
            new RuntimeStopRequested([false]),
            new RuntimeCycleRunner([]),
            tokens: $tokens,
            heartbeats: $heartbeats,
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::RuntimeFailed, $result->code);
        self::assertSame([CollectorWorkerStatus::Ready, CollectorWorkerStatus::Stopping], $heartbeats->statuses);
    }

    public function testUnexpectedReadinessClockFailureBeforeInitializationReturnsRuntimeFailure(): void
    {
        $heartbeats = new RuntimeHeartbeatStore();
        $result = $this->loop(
            new RuntimeScheduleStore([]),
            new RuntimeStopRequested([]),
            new RuntimeCycleRunner([]),
            heartbeats: $heartbeats,
            clockFailure: new RuntimeException('clock secret'),
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::RuntimeFailed, $result->code);
        self::assertSame([], $heartbeats->statuses);
    }

    public function testUnexpectedWorkFailureIsFinalizedAsFailedAndReturnsOnlyAStableCode(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested(array_fill(0, 6, false)),
            new RuntimeCycleRunner([new RuntimeException('contains a secret')]),
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::RuntimeFailed, $result->code);
        self::assertSame([CollectorCycleStatus::Failed], $schedule->finalized);
        self::assertStringNotContainsString('secret', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testOwnershipLossWhileBestEffortFinishingDoesNotTryAnotherWrite(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $schedule->finalizeFailure = new CollectorLeaseOwnershipLost('lost');
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested(array_fill(0, 6, false)),
            new RuntimeCycleRunner([new RuntimeException('boom')]),
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::LeaseOwnershipLost, $result->code);
        self::assertSame(1, $schedule->finalizeAttempts);
    }

    public function testOwnershipLossWhileNormallyFinalizingReturnsTypedFailure(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $schedule->finalizeFailure = new CollectorLeaseOwnershipLost('lost');
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested(array_fill(0, 6, false)),
            new RuntimeCycleRunner([CollectorCycleStatus::Succeeded]),
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::LeaseOwnershipLost, $result->code);
        self::assertSame(1, $schedule->finalizeAttempts);
    }

    public function testNonOwnershipFinalizeFailureReturnsRuntimeFailure(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $schedule->finalizeFailure = new RuntimeException('database detail');
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested(array_fill(0, 6, false)),
            new RuntimeCycleRunner([CollectorCycleStatus::Succeeded]),
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::RuntimeFailed, $result->code);
        self::assertSame(1, $schedule->finalizeAttempts);
    }

    /** @return iterable<string, array{\Throwable, CollectorWorkerRunCode}> */
    public static function shutdownFinalizeFailures(): iterable
    {
        yield 'ownership' => [new CollectorLeaseOwnershipLost('lost'), CollectorWorkerRunCode::LeaseOwnershipLost];
        yield 'runtime' => [new RuntimeException('detail'), CollectorWorkerRunCode::RuntimeFailed];
    }

    #[DataProvider('shutdownFinalizeFailures')]
    public function testShutdownFinalizeFailuresAreTypedAndNeverRetried(
        \Throwable $failure,
        CollectorWorkerRunCode $code,
    ): void {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $schedule->finalizeFailure = $failure;
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested([false, true]),
            new RuntimeCycleRunner([]),
        )->run($this->worker(), true);

        self::assertSame($code, $result->code);
        self::assertSame(1, $schedule->finalizeAttempts);
    }

    public function testBestEffortFailureFinalizeRuntimeErrorIsStableAndNotRetried(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $schedule->finalizeFailure = new RuntimeException('detail');
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested(array_fill(0, 6, false)),
            new RuntimeCycleRunner([new RuntimeException('work detail')]),
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::RuntimeFailed, $result->code);
        self::assertSame(1, $schedule->finalizeAttempts);
    }

    public function testBestEffortIdleHeartbeatFailuresDoNotTurnRetriesOrStopsIntoRestartLoops(): void
    {
        $heartbeats = new RuntimeHeartbeatStore([
            CollectorWorkerStatus::Degraded,
            CollectorWorkerStatus::Stopping,
        ]);
        $result = $this->loop(
            new RuntimeScheduleStore([$this->waiting('+1 second')]),
            new RuntimeStopRequested([false, false, false, true]),
            new RuntimeCycleRunner([]),
            readiness: [
                ReadinessCheckResult::ready('database_schema'),
                ReadinessCheckResult::unavailable('database_schema', 'database_unavailable'),
            ],
            heartbeats: $heartbeats,
        )->run($this->worker(), false);

        self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        self::assertSame([CollectorWorkerStatus::Ready, CollectorWorkerStatus::Ready], $heartbeats->statuses);
    }

    public function testAbandonedWorkResultIsConvertedToFailedFinalization(): void
    {
        $schedule = new RuntimeScheduleStore([$this->claimed()]);
        $result = $this->loop(
            $schedule,
            new RuntimeStopRequested(array_fill(0, 6, false)),
            new RuntimeCycleRunner([CollectorCycleStatus::Abandoned]),
        )->run($this->worker(), true);

        self::assertSame(CollectorWorkerRunCode::RuntimeFailed, $result->code);
        self::assertSame([CollectorCycleStatus::Failed], $schedule->finalized);
    }

    public function testRuntimeConfigurationRejectsNonPositiveGridWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loop(
            new RuntimeScheduleStore([]),
            new RuntimeStopRequested([]),
            new RuntimeCycleRunner([]),
            gridWidth: 0,
        );
    }

    public function testRuntimeConfigurationRejectsGridWiderThanOneYear(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loop(
            new RuntimeScheduleStore([]),
            new RuntimeStopRequested([]),
            new RuntimeCycleRunner([]),
            gridWidth: GridSchedule::MAXIMUM_WIDTH_SECONDS + 1,
        );
    }

    public function testRuntimeAcceptsBothGridWidthBoundaries(): void
    {
        $minimum = $this->loop(
            new RuntimeScheduleStore([]),
            new RuntimeStopRequested([]),
            new RuntimeCycleRunner([]),
            gridWidth: 1,
        );
        $maximum = $this->loop(
            new RuntimeScheduleStore([]),
            new RuntimeStopRequested([]),
            new RuntimeCycleRunner([]),
            gridWidth: GridSchedule::MAXIMUM_WIDTH_SECONDS,
        );

        self::assertInstanceOf(CollectorRuntimeLoop::class, $minimum);
        self::assertInstanceOf(CollectorRuntimeLoop::class, $maximum);
    }

    public function testMaintenancePreventsClaimingAndPreservesTheContinuousProcess(): void
    {
        foreach ([true, false] as $once) {
            $gate = $this->createStub(\App\Application\Maintenance\MaintenanceAccess::class);
            $gate->method('acquire')->willReturn(null);
            $schedule = new RuntimeScheduleStore([]);
            $result = $this->loop($schedule, new RuntimeStopRequested([false, true]), new RuntimeCycleRunner([]), maintenance: $gate)->run($this->worker(), $once);
            self::assertSame($once ? CollectorWorkerRunCode::NoCycleDue : CollectorWorkerRunCode::CollectorStopped, $result->code);
            self::assertSame([], $schedule->claimTokens);
        }
    }

    public function testIdleAndUnavailableReadinessReleaseMaintenancePermitBeforeWaiting(): void
    {
        foreach ([true, false] as $ready) {
            $permit = $this->createMock(\App\Application\Maintenance\MaintenancePermit::class);
            $released = false;
            $permit->expects(self::once())->method('release')->willReturnCallback(static function () use (&$released): void { $released = true; });
            $gate = $this->createMock(\App\Application\Maintenance\MaintenanceAccess::class);
            $gate->expects(self::exactly(2))->method('acquire')->willReturnOnConsecutiveCalls($permit, null);
            $waiter = $this->createMock(CollectorRuntimeWaiter::class);
            $waiter->expects(self::once())->method('wait')->with(30)->willReturnCallback(static function () use (&$released): void { self::assertTrue($released, 'Idle sleep must not hold the maintenance lock.'); });
            $result = $this->loop(
                new RuntimeScheduleStore([$this->waiting('+120 seconds')]),
                new RuntimeStopRequested($ready ? [false, false, true] : [false, true]),
                new RuntimeCycleRunner([]),
                waiter: $waiter,
                readiness: [$ready ? ReadinessCheckResult::ready('database_schema') : ReadinessCheckResult::unavailable('database_schema', 'check_failed')],
                maintenance: $gate,
            )->run($this->worker(), false);
            self::assertSame(CollectorWorkerRunCode::CollectorStopped, $result->code);
        }
    }

    /** @param list<ReadinessCheckResult> $readiness */
    private function loop(
        RuntimeScheduleStore $schedule,
        RuntimeStopRequested $stop,
        RuntimeCycleRunner $runner,
        ?RuntimeTokenFactory $tokens = null,
        ?CollectorRuntimeWaiter $waiter = null,
        array $readiness = [],
        ?RuntimeHeartbeatStore $heartbeats = null,
        int $gridWidth = 120,
        ?\Throwable $clockFailure = null,
        ?\App\Application\Maintenance\MaintenanceAccess $maintenance = null,
    ): CollectorRuntimeLoop {
        return new CollectorRuntimeLoop(
            new CollectorCycleCoordinator(
                $schedule,
                $heartbeats ?? new RuntimeHeartbeatStore(),
                new RuntimeMonotonicClock(),
            ),
            $runner,
            new WorkerReadinessProbe(
                new RuntimeClock($clockFailure),
                new ReadinessAggregator([
                    new RuntimeReadinessCheck([] === $readiness
                        ? [ReadinessCheckResult::ready('database_schema')]
                        : $readiness),
                ]),
            ),
            $tokens ?? new RuntimeTokenFactory(),
            $stop,
            $waiter ?? new RuntimeWaiter(),
            $gridWidth,
            $maintenance ?? new \App\Application\Maintenance\UnrestrictedMaintenanceAccess(),
        );
    }

    private function worker(): CollectorWorkerId
    {
        return new CollectorWorkerId(str_repeat('w', 16));
    }

    private function waiting(string $modifier): CollectorClaimDecision
    {
        $now = new DateTimeImmutable(self::NOW);

        return CollectorClaimDecision::waiting($now, $now->modify($modifier));
    }

    private function claimed(string $tokenByte = 't'): CollectorClaimDecision
    {
        $now = new DateTimeImmutable(self::NOW);

        return CollectorClaimDecision::claimed(
            new CollectorLease(
                $this->worker(),
                new CollectorCycleToken(str_repeat($tokenByte, 16)),
                1,
                $now->modify('+4 minutes'),
            ),
            $now,
            $now,
        );
    }
}

final class RuntimeScheduleStore implements CollectorScheduleStore
{
    /** @var list<CollectorClaimDecision> */
    private array $decisions;
    /** @var list<string> */
    public array $claimTokens = [];
    /** @var list<CollectorCycleStatus> */
    public array $finalized = [];
    public int $bootstraps = 0;
    public int $renewals = 0;
    public int $finalizeAttempts = 0;
    public ?\Throwable $renewFailure = null;
    public ?\Throwable $finalizeFailure = null;

    /** @param list<CollectorClaimDecision> $decisions */
    public function __construct(array $decisions) { $this->decisions = $decisions; }

    public function bootstrap(int $gridWidthSeconds): CollectorScheduleSnapshot
    {
        ++$this->bootstraps;
        $now = new DateTimeImmutable(CollectorRuntimeLoopTest::NOW);

        return new CollectorScheduleSnapshot(new GridSchedule($now, $gridWidthSeconds), $now, $now);
    }

    public function claimDue(CollectorWorkerId $workerId, CollectorCycleToken $cycleToken, int $leaseTtlSeconds): CollectorClaimDecision
    {
        $this->claimTokens[] = $cycleToken->binary();
        $decision = array_shift($this->decisions);
        if (!$decision instanceof CollectorClaimDecision) {
            $now = new DateTimeImmutable(CollectorRuntimeLoopTest::NOW);

            return CollectorClaimDecision::waiting($now, $now->modify('+2 minutes'));
        }

        return $decision;
    }

    public function renew(CollectorLease $lease, int $leaseTtlSeconds): CollectorLease
    {
        ++$this->renewals;
        if ($this->renewFailure instanceof \Throwable) {
            throw $this->renewFailure;
        }

        return new CollectorLease(
            $lease->ownerId,
            $lease->token,
            $lease->fencingToken,
            $lease->expiresAt->modify('+1 second'),
        );
    }

    public function finalize(CollectorLease $lease, CollectorCycleStatus $status, int $durationMilliseconds): DateTimeImmutable
    {
        ++$this->finalizeAttempts;
        if ($this->finalizeFailure instanceof \Throwable) {
            throw $this->finalizeFailure;
        }
        $this->finalized[] = $status;

        return (new DateTimeImmutable(CollectorRuntimeLoopTest::NOW))->modify('+2 minutes');
    }
}

final class RuntimeHeartbeatStore implements CollectorHeartbeatStore
{
    /** @var list<CollectorWorkerStatus> */
    public array $statuses = [];

    /** @param list<CollectorWorkerStatus> $failureStatuses */
    public function __construct(private readonly array $failureStatuses = []) {}

    public function record(
        CollectorWorkerId $workerId,
        CollectorWorkerStatus $status,
        int $ttlSeconds,
        string $buildVersion,
        ?CollectorCycleToken $cycleToken = null,
        ?DateTimeImmutable $nextActionAt = null,
    ): void {
        if (in_array($status, $this->failureStatuses, true)) {
            throw new RuntimeException('heartbeat_failed');
        }
        $this->statuses[] = $status;
    }

    public function isFresh(CollectorWorkerId $workerId): bool { return true; }
}

final class RuntimeCycleRunner implements RunCollectorCycle
{
    /** @var list<CollectorCycleStatus|\Throwable> */
    private array $outcomes;
    public int $calls = 0;

    /** @param list<CollectorCycleStatus|\Throwable> $outcomes */
    public function __construct(array $outcomes) { $this->outcomes = $outcomes; }

    public function execute(CollectorActiveCycle $cycle): CollectorCycleResult
    {
        ++$this->calls;
        $outcome = array_shift($this->outcomes) ?? CollectorCycleStatus::Succeeded;
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return new RuntimeCycleResult($outcome);
    }
}

final readonly class RuntimeCycleResult implements CollectorCycleResult
{
    public function __construct(private CollectorCycleStatus $cycleStatus) {}
    public function status(): CollectorCycleStatus { return $this->cycleStatus; }
}

final class RuntimeTokenFactory implements CollectorCycleTokenFactory
{
    public int $generated = 0;
    public function __construct(private readonly ?\Throwable $failure = null) {}
    public function generate(): CollectorCycleToken
    {
        ++$this->generated;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return new CollectorCycleToken(substr(hash('sha256', (string) $this->generated, true), 0, 16));
    }
}

final class RuntimeIdentity implements CollectorWorkerIdentity
{
    public int $reads = 0;
    public function __construct(private readonly ?\Throwable $failure = null) {}
    public function workerId(): CollectorWorkerId
    {
        ++$this->reads;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return new CollectorWorkerId(str_repeat('w', 16));
    }
}

final class RuntimeStopRequested implements StopRequested
{
    /** @var list<bool> */
    private array $values;
    private bool $last = false;
    private int $calls = 0;
    /** @param list<bool> $values */
    public function __construct(array $values, private readonly int $maximumCalls = 64)
    {
        $this->values = $values;
    }

    public function isStopRequested(): bool
    {
        ++$this->calls;
        if ($this->calls > $this->maximumCalls) {
            throw new RuntimeException('Stop-request call budget exceeded.');
        }
        if ([] !== $this->values) {
            $this->last = (bool) array_shift($this->values);
        }

        return $this->last;
    }
}

final class RuntimeWaiter implements CollectorRuntimeWaiter
{
    /** @var list<int> */
    public array $seconds = [];
    public function wait(int $seconds): void { $this->seconds[] = $seconds; }
}

final class RuntimeReadinessCheck implements ReadinessCheck
{
    /** @var list<ReadinessCheckResult> */
    private array $results;
    private ?ReadinessCheckResult $last = null;
    /** @param list<ReadinessCheckResult> $results */
    public function __construct(array $results) { $this->results = $results; }
    public function name(): string { return 'database_schema'; }
    public function check(): ReadinessCheckResult
    {
        $this->last = array_shift($this->results) ?? $this->last;

        return $this->last ?? ReadinessCheckResult::unavailable('database_schema', 'check_failed');
    }
}

final class RuntimeClock implements Clock
{
    public function __construct(private readonly ?\Throwable $failure = null) {}
    public function now(): DateTimeImmutable
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return new DateTimeImmutable(CollectorRuntimeLoopTest::NOW);
    }
}

final class RuntimeMonotonicClock implements MonotonicClock
{
    private int $now = 0;
    public function nowNanoseconds(): int { return ++$this->now * 1_000_000; }
}
