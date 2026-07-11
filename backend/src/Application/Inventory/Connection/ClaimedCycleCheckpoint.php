<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorCycleCoordinator;
use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorShutdownRequested;
use App\Application\Collector\StopRequested;
use DateTimeImmutable;

final class ClaimedCycleCheckpoint implements ConnectionReadCheckpoint
{
    public function __construct(
        private CollectorActiveCycle $cycle,
        private readonly CollectorCycleCoordinator $coordinator,
        private readonly StopRequested $stopRequested,
    ) {
    }

    public function checkpoint(): void
    {
        $this->stopIfRequested();
        $this->cycle = $this->coordinator->checkpoint($this->cycle);
        $this->stopIfRequested();
    }

    public function cycle(): CollectorActiveCycle
    {
        return $this->cycle;
    }

    public function lease(): CollectorLease
    {
        return $this->cycle->lease;
    }

    public function finish(CollectorCycleStatus $status): DateTimeImmutable
    {
        return $this->coordinator->finish($this->cycle, $status);
    }

    private function stopIfRequested(): void
    {
        if ($this->stopRequested->isStopRequested()) {
            throw new CollectorShutdownRequested($this->cycle);
        }
    }
}
