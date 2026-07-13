<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorLease;
use DateTimeImmutable;

interface AutomaticShadowEvaluationSource
{
    /** @return list<AutomaticShadowCandidate> */
    public function candidates(CollectorLease $lease): array;

    public function recordCounterReset(
        CollectorLease $lease,
        AutomaticShadowCandidate $candidate,
        DateTimeImmutable $detectedAt,
    ): void;
}
