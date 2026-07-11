<?php

declare(strict_types=1);

namespace App\Application\Collector;

interface CollectorCycleResult
{
    public function status(): CollectorCycleStatus;
}
