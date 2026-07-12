<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorLease;

final readonly class PersistShadowEvaluation
{
    public function __construct(private ShadowEvaluationStore $store)
    {
    }

    public function execute(
        CollectorLease $lease,
        ShadowEvaluationBatch $batch,
    ): ShadowEvaluationPersistenceResult {
        if (!hash_equals($lease->token->binary(), $batch->cycleToken->binary())) {
            throw new ShadowEvaluationConflict(
                ShadowEvaluationConflictCode::CycleTokenMismatch,
                'The shadow evaluation does not belong to the active collector cycle.',
            );
        }

        return $this->store->persist($lease, $batch);
    }
}
