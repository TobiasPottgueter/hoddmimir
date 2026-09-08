<?php

declare(strict_types=1);

namespace App\Application\Backup\Metrics;

use DateTimeImmutable;

interface QueueMetricStore
{
    public function sample(DateTimeImmutable $at, DateTimeImmutable $retainSince): void;

    /** @return list<array{observedAt:string,samples:int,waitingAverage:float,waitingPeak:int,activePeak:int,unresolvedPeak:int,oldestWaitSeconds:int}> */
    public function history(QueueMetricWindow $window, ?string $targetId): array;
}
