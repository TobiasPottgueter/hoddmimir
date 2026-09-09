<?php

declare(strict_types=1);

namespace App\Application\Collector;

interface RunCollectorCycle
{
    public function execute(CollectorActiveCycle $cycle): CollectorCycleResult;
}
