<?php

declare(strict_types=1);

namespace App\Application\Configuration\Policy;

final readonly class PolicyActivationAssessment
{
    /** @param list<PolicyActivationBlockerCode> $blockers */
    public function __construct(public array $blockers)
    {
    }

    public function canEnable(): bool
    {
        return [] === $this->blockers;
    }
}
