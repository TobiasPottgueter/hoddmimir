<?php

declare(strict_types=1);

namespace App\Application\Backup\Metrics;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorCycleResult;
use App\Application\Collector\RunCollectorCycle;

final readonly class QueueMetricsCollectorCycle implements RunCollectorCycle
{
    public function __construct(private RunCollectorCycle $inner, private SampleQueueMetrics $sampler) {}

    public function execute(CollectorActiveCycle $cycle): CollectorCycleResult
    {
        $this->sampler->execute();
        return $this->inner->execute($cycle);
    }
}
