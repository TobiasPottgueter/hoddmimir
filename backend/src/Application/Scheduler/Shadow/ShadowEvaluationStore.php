<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorLease;

interface ShadowEvaluationStore
{
    public function persist(
        CollectorLease $lease,
        ShadowEvaluationBatch $batch,
    ): ShadowEvaluationPersistenceResult;
}
