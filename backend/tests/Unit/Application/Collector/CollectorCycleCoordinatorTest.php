<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Collector;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorClaimDecision;
use App\Application\Collector\CollectorCycleCoordinator;
use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorHeartbeatStore;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorScheduleSnapshot;
use App\Application\Collector\CollectorScheduleStore;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorWorkerStatus;
use App\Application\Collector\GridSchedule;
use App\Application\Worker\MonotonicClock;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectorCycleCoordinatorTest extends TestCase
{
    public const string NOW = '2026-07-11T10:00:00+00:00';

    /** @return iterable<string, array{int, int, string}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'lease ttl' => [0, 150, 'development'];
        yield 'heartbeat ttl' => [240, 0, 'development'];
        yield 'empty version' => [240, 150, ''];
        yield 'long version' => [240, 150, 'v'.str_repeat('x', 64)];
    }

    #[DataProvider('invalidConfiguration')]
    public function testItRejectsInvalidConfiguration(int $leaseTtl, int $heartbeatTtl, string $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CollectorCycleCoordinator(
            new RecordingScheduleStore(),
            new RecordingHeartbeatStore(),
            new SequenceMonotonicClock([0]),
            $leaseTtl,
            $heartbeatTtl,
            $build,
        );
    }

    public function testInitializePersistsTheGridAndReadyHeartbeat(): void
    {
        $store = new RecordingScheduleStore();
        $heartbeats = new RecordingHeartbeatStore();
        $coordinator = $this->coordinator($store, $heartbeats, [0]);

        $snapshot = $coordinator->initialize($this->worker(), 120);

        self::assertSame(120, $store->bootstrappedWidth);
        self::assertSame(self::NOW, $snapshot->nextScanAt->format('Y-m-d\TH:i:sP'));
        self::assertSame(CollectorWorkerStatus::Ready, $heartbeats->records[0]['status']);
        self::assertSame($snapshot->nextScanAt, $heartbeats->records[0]['next']);
    }

    public function testWaitingDecisionDoesNotCreateACycleAndRefreshesReadyHeartbeat(): void
    {
        $now = $this->now();
        $retryAt = $now->modify('+2 minutes');
        $store = new RecordingScheduleStore();
        $store->claimDecision = CollectorClaimDecision::waiting($now, $retryAt);
        $heartbeats = new RecordingHeartbeatStore();

        $decision = $this->coordinator($store, $heartbeats, [0])->tryStart($this->worker(), $this->token());

        self::assertFalse($decision->isStarted());
        self::assertSame($retryAt, $decision->retryAt);
        self::assertSame(CollectorWorkerStatus::Ready, $heartbeats->records[0]['status']);
        self::assertNull($heartbeats->records[0]['cycle']);
    }

    public function testClaimCheckpointAndRenewedBusyHeartbeatPreserveMonotonicStart(): void
    {
        $store = new RecordingScheduleStore();
        $store->claimDecision = $this->claimedDecision();
        $heartbeats = new RecordingHeartbeatStore();
        $coordinator = $this->coordinator($store, $heartbeats, [1_000_000]);

        $started = $coordinator->tryStart($this->worker(), $this->token());
        self::assertTrue($started->isStarted());
        self::assertNotNull($started->cycle);
        self::assertSame(CollectorWorkerStatus::Busy, $heartbeats->records[0]['status']);

        $renewed = $coordinator->checkpoint($started->cycle);
        self::assertSame(1_000_000, $renewed->startedMonotonicNanoseconds);
        self::assertSame(1, $renewed->lease->fencingToken);
        self::assertSame(CollectorWorkerStatus::Busy, $heartbeats->records[1]['status']);
    }

    public function testBusyHeartbeatFailureBestEffortFinalizesTheJustClaimedCycleAsFailed(): void
    {
        $store = new RecordingScheduleStore();
        $store->claimDecision = $this->claimedDecision();
        $heartbeats = new RecordingHeartbeatStore();
        $heartbeats->failureStatus = CollectorWorkerStatus::Busy;

        try {
            $this->coordinator($store, $heartbeats, [1_000_000])->tryStart($this->worker(), $this->token());
            self::fail('The busy heartbeat failure was swallowed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('heartbeat_failed', $failure->getMessage());
        }

        self::assertSame(CollectorCycleStatus::Failed, $store->finalStatus);
        self::assertSame(0, $store->finalDurationMilliseconds);
    }

    public function testOwnershipLossDuringClaimCleanupWinsWithoutAnotherHeartbeatWrite(): void
    {
        $store = new RecordingScheduleStore();
        $store->claimDecision = $this->claimedDecision();
        $store->finalFailure = new CollectorLeaseOwnershipLost('lost');
        $heartbeats = new RecordingHeartbeatStore();
        $heartbeats->failureStatus = CollectorWorkerStatus::Busy;

        $this->expectException(CollectorLeaseOwnershipLost::class);
        $this->coordinator($store, $heartbeats, [1_000_000])->tryStart($this->worker(), $this->token());
    }

    public function testOriginalBusyHeartbeatFailureWinsWhenBestEffortCleanupAlsoFails(): void
    {
        $store = new RecordingScheduleStore();
        $store->claimDecision = $this->claimedDecision();
        $store->finalFailure = new \RuntimeException('cleanup_failed');
        $heartbeats = new RecordingHeartbeatStore();
        $heartbeats->failureStatus = CollectorWorkerStatus::Busy;

        $this->expectExceptionMessage('heartbeat_failed');
        $this->coordinator($store, $heartbeats, [1_000_000])->tryStart($this->worker(), $this->token());
    }

    /** @return iterable<string, array{CollectorCycleStatus, CollectorWorkerStatus, bool}> */
    public static function terminalOutcomes(): iterable
    {
        yield 'succeeded' => [CollectorCycleStatus::Succeeded, CollectorWorkerStatus::Ready, true];
        yield 'partial' => [CollectorCycleStatus::Partial, CollectorWorkerStatus::Degraded, true];
        yield 'failed' => [CollectorCycleStatus::Failed, CollectorWorkerStatus::Degraded, true];
        yield 'cancelled' => [CollectorCycleStatus::Cancelled, CollectorWorkerStatus::Stopping, false];
    }

    #[DataProvider('terminalOutcomes')]
    public function testEveryTerminalOutcomeFinalizesWithMonotonicDurationAndSafeHeartbeat(
        CollectorCycleStatus $status,
        CollectorWorkerStatus $expectedWorkerStatus,
        bool $hasNextAction,
    ): void {
        $store = new RecordingScheduleStore();
        $store->claimDecision = $this->claimedDecision();
        $heartbeats = new RecordingHeartbeatStore();
        $coordinator = $this->coordinator($store, $heartbeats, [1_000_000_000, 3_345_999_999]);
        $decision = $coordinator->tryStart($this->worker(), $this->token());
        self::assertNotNull($decision->cycle);

        $next = $coordinator->finish($decision->cycle, $status);

        self::assertSame($store->finalNext, $next);
        self::assertSame($status, $store->finalStatus);
        self::assertSame(2_345, $store->finalDurationMilliseconds);
        self::assertSame($expectedWorkerStatus, $heartbeats->records[1]['status']);
        self::assertSame($hasNextAction ? $next : null, $heartbeats->records[1]['next']);
    }

    public function testOnlyTakeoverMayAbandonACycle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('takeover');
        $this->coordinator(new RecordingScheduleStore(), new RecordingHeartbeatStore(), [0])
            ->finish($this->activeCycle(0), CollectorCycleStatus::Abandoned);
    }

    public function testItRejectsAMonotonicClockMovingBackwards(): void
    {
        $store = new RecordingScheduleStore();
        $heartbeats = new RecordingHeartbeatStore();
        $coordinator = $this->coordinator($store, $heartbeats, [99]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('backwards');
        $coordinator->finish($this->activeCycle(100), CollectorCycleStatus::Succeeded);
    }

    public function testIdleStopPublishesStoppingWithoutACycle(): void
    {
        $heartbeats = new RecordingHeartbeatStore();
        $this->coordinator(new RecordingScheduleStore(), $heartbeats, [0])->stopIdle($this->worker());

        self::assertSame(CollectorWorkerStatus::Stopping, $heartbeats->records[0]['status']);
        self::assertNull($heartbeats->records[0]['cycle']);
    }

    public function testIdleDegradationRefreshesHeartbeatWithoutACycle(): void
    {
        $heartbeats = new RecordingHeartbeatStore();
        $this->coordinator(new RecordingScheduleStore(), $heartbeats, [0])->degradeIdle($this->worker());

        self::assertSame(CollectorWorkerStatus::Degraded, $heartbeats->records[0]['status']);
        self::assertNull($heartbeats->records[0]['cycle']);
        self::assertNull($heartbeats->records[0]['next']);
    }

    /** @param list<int> $monotonicValues */
    private function coordinator(
        RecordingScheduleStore $store,
        RecordingHeartbeatStore $heartbeats,
        array $monotonicValues,
    ): CollectorCycleCoordinator {
        return new CollectorCycleCoordinator(
            $store,
            $heartbeats,
            new SequenceMonotonicClock($monotonicValues),
            240,
            150,
            'test-build',
        );
    }

    private function claimedDecision(): CollectorClaimDecision
    {
        $now = $this->now();
        return CollectorClaimDecision::claimed($this->lease(1), $now, $now);
    }

    private function activeCycle(int $startedNanoseconds): CollectorActiveCycle
    {
        return new CollectorActiveCycle($this->lease(1), $this->now(), $startedNanoseconds);
    }

    private function lease(int $fence): CollectorLease
    {
        return new CollectorLease($this->worker(), $this->token(), $fence, $this->now()->modify('+4 minutes'));
    }

    private function worker(): CollectorWorkerId
    {
        return new CollectorWorkerId(str_repeat('a', 16));
    }

    private function token(): CollectorCycleToken
    {
        return new CollectorCycleToken(str_repeat('b', 16));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}

final class RecordingScheduleStore implements CollectorScheduleStore
{
    public ?int $bootstrappedWidth = null;
    public ?CollectorClaimDecision $claimDecision = null;
    public ?CollectorCycleStatus $finalStatus = null;
    public ?int $finalDurationMilliseconds = null;
    public DateTimeImmutable $finalNext;
    public ?\Throwable $finalFailure = null;

    public function __construct()
    {
        $this->finalNext = new DateTimeImmutable('2026-07-11T10:02:00+00:00');
    }

    public function bootstrap(int $gridWidthSeconds): CollectorScheduleSnapshot
    {
        $this->bootstrappedWidth = $gridWidthSeconds;
        $now = new DateTimeImmutable(CollectorCycleCoordinatorTest::NOW);
        return new CollectorScheduleSnapshot(new GridSchedule($now, $gridWidthSeconds), $now, $now);
    }

    public function claimDue(CollectorWorkerId $workerId, CollectorCycleToken $cycleToken, int $leaseTtlSeconds): CollectorClaimDecision
    {
        TestCase::assertSame(240, $leaseTtlSeconds);
        TestCase::assertSame(str_repeat('a', 16), $workerId->bytes);
        TestCase::assertSame(str_repeat('b', 16), $cycleToken->binary());
        return $this->claimDecision ?? CollectorClaimDecision::waiting($this->finalNext, $this->finalNext);
    }

    public function renew(CollectorLease $lease, int $leaseTtlSeconds): CollectorLease
    {
        return new CollectorLease(
            $lease->ownerId,
            $lease->token,
            $lease->fencingToken,
            $lease->expiresAt->modify('+4 minutes'),
        );
    }

    public function finalize(CollectorLease $lease, CollectorCycleStatus $status, int $durationMilliseconds): DateTimeImmutable
    {
        if ($this->finalFailure instanceof \Throwable) {
            throw $this->finalFailure;
        }
        $this->finalStatus = $status;
        $this->finalDurationMilliseconds = $durationMilliseconds;
        return $this->finalNext;
    }

}

final class RecordingHeartbeatStore implements CollectorHeartbeatStore
{
    /** @var list<array{status: CollectorWorkerStatus, cycle: ?CollectorCycleToken, next: ?DateTimeImmutable}> */
    public array $records = [];
    public ?CollectorWorkerStatus $failureStatus = null;

    public function record(
        CollectorWorkerId $workerId,
        CollectorWorkerStatus $status,
        int $ttlSeconds,
        string $buildVersion,
        ?CollectorCycleToken $cycleToken = null,
        ?DateTimeImmutable $nextActionAt = null,
    ): void {
        if ($status === $this->failureStatus) {
            throw new \RuntimeException('heartbeat_failed');
        }
        TestCase::assertSame(150, $ttlSeconds);
        TestCase::assertSame('test-build', $buildVersion);
        $this->records[] = ['status' => $status, 'cycle' => $cycleToken, 'next' => $nextActionAt];
    }

    public function isFresh(CollectorWorkerId $workerId): bool
    {
        return true;
    }
}

final class SequenceMonotonicClock implements MonotonicClock
{
    /** @param list<int> $values */
    public function __construct(private array $values)
    {
    }

    public function nowNanoseconds(): int
    {
        $value = array_shift($this->values);
        TestCase::assertIsInt($value);
        return $value;
    }
}
