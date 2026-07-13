<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorLease;

interface AutomaticShadowEvaluator
{
    public function execute(CollectorLease $lease): ShadowEvaluationPersistenceResult;
}
