<?php

declare(strict_types=1);

namespace App\Application\Worker;

use App\Application\Readiness\ReadinessAggregator;
use App\Domain\Shared\Clock;
use App\Domain\Worker\WorkerKind;

final readonly class WorkerReadinessProbe
{
    public function __construct(
        private Clock $clock,
        private ReadinessAggregator $readiness,
    ) {
    }

    public function probe(WorkerKind $worker): WorkerReadinessReport
    {
        return new WorkerReadinessReport($worker, $this->clock->now(), $this->readiness->assess());
    }
}
