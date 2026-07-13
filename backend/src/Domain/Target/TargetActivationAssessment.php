<?php

declare(strict_types=1);

namespace App\Domain\Target;

final readonly class TargetActivationAssessment
{
    /** @param list<TargetActivationBlocker> $blockers */
    public function __construct(public array $blockers)
    {
    }

    public function canEnable(): bool
    {
        return [] === $this->blockers;
    }
}
