<?php

declare(strict_types=1);

namespace App\Application\Collector;

use App\Application\Worker\MonotonicClock;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CollectorCycleCoordinator
{
    public function __construct(
        private CollectorScheduleStore $scheduleStore,
        private CollectorHeartbeatStore $heartbeatStore,
        private MonotonicClock $monotonicClock,
        private int $leaseTtlSeconds = 240,
        private int $heartbeatTtlSeconds = 150,
        private string $buildVersion = 'development',
    ) {
        if ($this->leaseTtlSeconds < 1 || $this->heartbeatTtlSeconds < 1) {
            throw new InvalidArgumentException('Collector lease and heartbeat TTLs must be positive.');
        }

        if ('' === $this->buildVersion || strlen($this->buildVersion) > 64) {
            throw new InvalidArgumentException('The collector build version must contain between 1 and 64 bytes.');
        }
    }

    public function initialize(CollectorWorkerId $workerId, int $gridWidthSeconds): CollectorScheduleSnapshot
    {
        $schedule = $this->scheduleStore->bootstrap($gridWidthSeconds);
        $this->heartbeatStore->record(
            $workerId,
            CollectorWorkerStatus::Ready,
            $this->heartbeatTtlSeconds,
            $this->buildVersion,
            nextActionAt: $schedule->nextScanAt,
        );

        return $schedule;
    }

    public function tryStart(
        CollectorWorkerId $workerId,
        CollectorCycleToken $cycleToken,
    ): CollectorStartDecision {
        $decision = $this->scheduleStore->claimDue($workerId, $cycleToken, $this->leaseTtlSeconds);

        if (null === $decision->lease || null === $decision->scheduledFor) {
            $this->heartbeatStore->record(
                $workerId,
                CollectorWorkerStatus::Ready,
                $this->heartbeatTtlSeconds,
                $this->buildVersion,
                nextActionAt: $decision->retryAt,
            );

            return new CollectorStartDecision(null, $decision->databaseNow, $decision->retryAt);
        }

        $cycle = new CollectorActiveCycle(
            $decision->lease,
            $decision->scheduledFor,
            $this->monotonicClock->nowNanoseconds(),
        );
        try {
            $this->heartbeatStore->record(
                $workerId,
                CollectorWorkerStatus::Busy,
                $this->heartbeatTtlSeconds,
                $this->buildVersion,
                $cycleToken,
                $decision->lease->expiresAt,
            );
        } catch (\Throwable $heartbeatFailure) {
            try {
                $this->scheduleStore->finalize(
                    $decision->lease,
                    CollectorCycleStatus::Failed,
                    0,
                );
            } catch (CollectorLeaseOwnershipLost $ownershipLost) {
                throw $ownershipLost;
            } catch (\Throwable) {
                // Preserve the original failure after the best-effort terminal write.
            }

            throw $heartbeatFailure;
        }

        return new CollectorStartDecision($cycle, $decision->databaseNow, $decision->retryAt);
    }

    public function checkpoint(CollectorActiveCycle $cycle): CollectorActiveCycle
    {
        $lease = $this->scheduleStore->renew($cycle->lease, $this->leaseTtlSeconds);
        $this->heartbeatStore->record(
            $lease->ownerId,
            CollectorWorkerStatus::Busy,
            $this->heartbeatTtlSeconds,
            $this->buildVersion,
            $lease->token,
            $lease->expiresAt,
        );

        return new CollectorActiveCycle($lease, $cycle->scheduledFor, $cycle->startedMonotonicNanoseconds);
    }

    public function finish(CollectorActiveCycle $cycle, CollectorCycleStatus $status): DateTimeImmutable
    {
        if (CollectorCycleStatus::Abandoned === $status) {
            throw new InvalidArgumentException('Only lease takeover may abandon a collector cycle.');
        }

        $duration = $this->durationMilliseconds($cycle);
        $next = $this->scheduleStore->finalize($cycle->lease, $status, $duration);
        $workerStatus = match ($status) {
            CollectorCycleStatus::Partial, CollectorCycleStatus::Failed => CollectorWorkerStatus::Degraded,
            CollectorCycleStatus::Cancelled => CollectorWorkerStatus::Stopping,
            default => CollectorWorkerStatus::Ready,
        };
        $this->heartbeatStore->record(
            $cycle->lease->ownerId,
            $workerStatus,
            $this->heartbeatTtlSeconds,
            $this->buildVersion,
            nextActionAt: CollectorWorkerStatus::Stopping === $workerStatus ? null : $next,
        );

        return $next;
    }

    public function stopIdle(CollectorWorkerId $workerId): void
    {
        $this->heartbeatStore->record(
            $workerId,
            CollectorWorkerStatus::Stopping,
            $this->heartbeatTtlSeconds,
            $this->buildVersion,
        );
    }

    public function degradeIdle(CollectorWorkerId $workerId): void
    {
        $this->heartbeatStore->record(
            $workerId,
            CollectorWorkerStatus::Degraded,
            $this->heartbeatTtlSeconds,
            $this->buildVersion,
        );
    }

    private function durationMilliseconds(CollectorActiveCycle $cycle): int
    {
        $elapsed = $this->monotonicClock->nowNanoseconds() - $cycle->startedMonotonicNanoseconds;

        if ($elapsed < 0) {
            throw new InvalidArgumentException('The monotonic clock moved backwards during a collector cycle.');
        }

        return intdiv($elapsed, 1_000_000);
    }
}
