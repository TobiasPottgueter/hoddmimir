<?php

declare(strict_types=1);

namespace App\Application\Collector;

use App\Application\Inventory\Connection\ClaimedCycleCheckpoint;
use App\Application\Worker\WorkerReadinessProbe;
use App\Domain\Worker\WorkerKind;
use DateTimeImmutable;
use Throwable;

final readonly class CollectorRuntimeLoop
{
    private const int MAXIMUM_IDLE_WAIT_SECONDS = 30;

    public function __construct(
        private CollectorCycleCoordinator $coordinator,
        private RunCollectorCycle $runCycle,
        private WorkerReadinessProbe $readinessProbe,
        private CollectorCycleTokenFactory $cycleTokenFactory,
        private StopRequested $stopRequested,
        private CollectorRuntimeWaiter $waiter,
        private int $gridWidthSeconds = GridSchedule::DEFAULT_WIDTH_SECONDS,
        private \App\Application\Maintenance\MaintenanceAccess $maintenance = new \App\Application\Maintenance\UnrestrictedMaintenanceAccess(),
    ) {
        if ($this->gridWidthSeconds < 1 || $this->gridWidthSeconds > GridSchedule::MAXIMUM_WIDTH_SECONDS) {
            throw new \InvalidArgumentException('The collector grid width must be between one second and one year.');
        }
    }

    public function run(CollectorWorkerId $workerId, bool $once): CollectorWorkerRunResult
    {
        $initialized = false;
        $running = true;

        try {
            while ($running) {
                $permit = $this->maintenance->acquire(\App\Application\Maintenance\MaintenanceActivity::CollectInventory);
                if (null === $permit) {
                    if ($once) return new CollectorWorkerRunResult(CollectorWorkerRunCode::NoCycleDue);
                    if ($this->stopRequested->isStopRequested()) return new CollectorWorkerRunResult(CollectorWorkerRunCode::CollectorStopped);
                    $this->waiter->wait(self::MAXIMUM_IDLE_WAIT_SECONDS);
                    continue;
                }
                try {
                $readiness = $this->readinessProbe->probe(WorkerKind::Collector);
                if (!$readiness->isReady()) {
                    if ($once) {
                        return new CollectorWorkerRunResult(
                            CollectorWorkerRunCode::ReadinessUnavailable,
                            $readiness,
                        );
                    }

                    if ($this->stopRequested->isStopRequested()) {
                        $this->stopIdleBestEffort($workerId);

                        return new CollectorWorkerRunResult(CollectorWorkerRunCode::CollectorStopped);
                    }

                    if ($initialized) {
                        $this->degradeIdleBestEffort($workerId);
                    }
                    $permit->release();
                    $permit = null;
                    $this->waiter->wait(self::MAXIMUM_IDLE_WAIT_SECONDS);

                    continue;
                }

                if (!$initialized) {
                    $this->coordinator->initialize($workerId, $this->gridWidthSeconds);
                    $initialized = true;
                }

                if ($this->stopRequested->isStopRequested()) {
                    $running = false;

                    continue;
                }

                $decision = $this->coordinator->tryStart(
                    $workerId,
                    $this->cycleTokenFactory->generate(),
                );
                if (!$decision->isStarted()) {
                    if ($once) {
                        return new CollectorWorkerRunResult(CollectorWorkerRunCode::NoCycleDue);
                    }

                    if ($this->stopRequested->isStopRequested()) {
                        $this->coordinator->stopIdle($workerId);

                        return new CollectorWorkerRunResult(CollectorWorkerRunCode::CollectorStopped);
                    }

                    $permit->release();
                    $permit = null;
                    $this->waiter->wait($this->idleWaitSeconds($decision->databaseNow, $decision->retryAt));

                    continue;
                }

                /** @var CollectorActiveCycle $cycle */
                $cycle = $decision->cycle;
                $outcome = $this->runClaimedCycle($cycle);
                if ($once) {
                    return $outcome;
                }
                $continue = match ($outcome->code) {
                    CollectorWorkerRunCode::CycleSucceeded,
                    CollectorWorkerRunCode::CyclePartial,
                    CollectorWorkerRunCode::CycleFailed => true,
                    default => false,
                };
                if (!$continue) {
                    return $outcome;
                }
                } finally {
                    $permit?->release();
                }
            }

            $this->coordinator->stopIdle($workerId);

            return new CollectorWorkerRunResult(CollectorWorkerRunCode::CollectorStopped);
        } catch (CollectorLeaseOwnershipLost) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::LeaseOwnershipLost);
        } catch (Throwable) {
            if ($initialized) {
                $this->stopIdleBestEffort($workerId);
            }

            return new CollectorWorkerRunResult(CollectorWorkerRunCode::RuntimeFailed);
        }
    }

    private function runClaimedCycle(CollectorActiveCycle $cycle): CollectorWorkerRunResult
    {
        $checkpoint = new ClaimedCycleCheckpoint($cycle, $this->coordinator, $this->stopRequested);

        try {
            $checkpoint->checkpoint();
            $result = $this->runCycle->execute($checkpoint->cycle());
            $checkpoint->checkpoint();

            $status = $this->stopRequested->isStopRequested()
                ? CollectorCycleStatus::Cancelled
                : $result->status();
        } catch (CollectorShutdownRequested $shutdown) {
            return $this->finishAfterShutdown($shutdown->cycle);
        } catch (CollectorLeaseOwnershipLost) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::LeaseOwnershipLost);
        } catch (Throwable) {
            return $this->finishAfterFailure($checkpoint->cycle());
        }

        if (CollectorCycleStatus::Abandoned === $status) {
            return $this->finishAfterFailure($checkpoint->cycle());
        }
        $terminalResult = $this->resultForStatus($status);

        try {
            $checkpoint->finish($status);
        } catch (CollectorLeaseOwnershipLost) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::LeaseOwnershipLost);
        } catch (Throwable) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::RuntimeFailed);
        }

        return $terminalResult;
    }

    private function resultForStatus(CollectorCycleStatus $status): CollectorWorkerRunResult
    {
        if (CollectorCycleStatus::Succeeded === $status) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::CycleSucceeded);
        }
        if (CollectorCycleStatus::Partial === $status) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::CyclePartial);
        }
        if (CollectorCycleStatus::Failed === $status) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::CycleFailed);
        }

        return new CollectorWorkerRunResult(CollectorWorkerRunCode::CollectorStopped);
    }

    private function finishAfterShutdown(CollectorActiveCycle $cycle): CollectorWorkerRunResult
    {
        try {
            $this->coordinator->finish($cycle, CollectorCycleStatus::Cancelled);

            return new CollectorWorkerRunResult(CollectorWorkerRunCode::CollectorStopped);
        } catch (CollectorLeaseOwnershipLost) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::LeaseOwnershipLost);
        } catch (Throwable) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::RuntimeFailed);
        }
    }

    private function finishAfterFailure(CollectorActiveCycle $cycle): CollectorWorkerRunResult
    {
        try {
            $this->coordinator->finish($cycle, CollectorCycleStatus::Failed);
        } catch (CollectorLeaseOwnershipLost) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::LeaseOwnershipLost);
        } catch (Throwable) {
            return new CollectorWorkerRunResult(CollectorWorkerRunCode::RuntimeFailed);
        }

        return new CollectorWorkerRunResult(CollectorWorkerRunCode::RuntimeFailed);
    }

    private function idleWaitSeconds(DateTimeImmutable $databaseNow, DateTimeImmutable $retryAt): int
    {
        $nowMicroseconds = (int) ($databaseNow->format('U').$databaseNow->format('u'));
        $retryMicroseconds = (int) ($retryAt->format('U').$retryAt->format('u'));
        $difference = $retryMicroseconds - $nowMicroseconds;
        if ($difference <= 0) {
            return 1;
        }
        $seconds = intdiv($difference + 999_999, 1_000_000);
        if ($seconds > self::MAXIMUM_IDLE_WAIT_SECONDS) {
            return self::MAXIMUM_IDLE_WAIT_SECONDS;
        }

        return $seconds;
    }

    private function stopIdleBestEffort(CollectorWorkerId $workerId): void
    {
        try {
            $this->coordinator->stopIdle($workerId);
        } catch (Throwable) {
            // The original stable runtime result wins; no exception detail is exposed.
        }
    }

    private function degradeIdleBestEffort(CollectorWorkerId $workerId): void
    {
        try {
            $this->coordinator->degradeIdle($workerId);
        } catch (Throwable) {
            // Readiness stays fail-closed while a temporarily unavailable DB is retried.
        }
    }
}
