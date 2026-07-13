<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow\ReadModel;

use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\DecisionOutcome;

final readonly class ShadowDecisionSummary
{
    public function __construct(
        public string $id, public string $evaluationId, public string $guestId,
        public ?string $nodeId, public DecisionOutcome $outcome, public ?BackupReason $reason,
        public ?int $priority, public string $policyId, public int $policyRevision,
        public string $targetId, public int $targetRevision, public string $completedAt,
    ) {}
    /** @return array<string, mixed> */
    public function toArray(): array { return ['id' => $this->id, 'evaluationId' => $this->evaluationId, 'guestId' => $this->guestId, 'nodeId' => $this->nodeId, 'outcome' => $this->outcome->value, 'reason' => $this->reason?->value, 'priority' => $this->priority, 'policyId' => $this->policyId, 'policyRevision' => $this->policyRevision, 'targetId' => $this->targetId, 'targetRevision' => $this->targetRevision, 'completedAt' => $this->completedAt]; }
}
