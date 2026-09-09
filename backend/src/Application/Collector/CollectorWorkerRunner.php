<?php

declare(strict_types=1);

namespace App\Application\Collector;

interface CollectorWorkerRunner
{
    public function run(bool $once): CollectorWorkerRunResult;
}
