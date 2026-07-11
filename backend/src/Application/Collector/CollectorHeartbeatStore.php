<?php

declare(strict_types=1);

namespace App\Application\Collector;

use DateTimeImmutable;

interface CollectorHeartbeatStore
{
    public function record(
        CollectorWorkerId $workerId,
        CollectorWorkerStatus $status,
        int $ttlSeconds,
        string $buildVersion,
        ?CollectorCycleToken $cycleToken = null,
        ?DateTimeImmutable $nextActionAt = null,
    ): void;

    public function isFresh(CollectorWorkerId $workerId): bool;
}
