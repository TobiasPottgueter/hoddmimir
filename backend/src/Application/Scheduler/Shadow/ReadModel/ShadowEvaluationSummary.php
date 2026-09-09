<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow\ReadModel;

final readonly class ShadowEvaluationSummary
{
    public function __construct(
        public string $id, public string $cycleToken, public int $fencingToken,
        public int $evaluatorVersion, public int $decisionCount, public int $gateCount,
        public string $startedAt, public string $completedAt, public string $persistedAt,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array { return ['id' => $this->id, 'cycleToken' => $this->cycleToken, 'fencingToken' => $this->fencingToken, 'evaluatorVersion' => $this->evaluatorVersion, 'decisionCount' => $this->decisionCount, 'gateCount' => $this->gateCount, 'startedAt' => $this->startedAt, 'completedAt' => $this->completedAt, 'persistedAt' => $this->persistedAt]; }
}
