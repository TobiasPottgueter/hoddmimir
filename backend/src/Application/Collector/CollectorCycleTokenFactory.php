<?php

declare(strict_types=1);

namespace App\Application\Collector;

interface CollectorCycleTokenFactory
{
    public function generate(): CollectorCycleToken;
}
