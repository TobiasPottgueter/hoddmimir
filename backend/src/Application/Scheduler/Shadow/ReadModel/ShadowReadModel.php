<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow\ReadModel;

interface ShadowReadModel
{
    public function evaluations(ShadowPageQuery $query): ShadowEvaluationPage;
    public function decisions(ShadowPageQuery $query): ShadowDecisionPage;
    public function decision(string $id): ?ShadowDecisionDetail;
}
