<?php

declare(strict_types=1);

namespace App\Domain\Target;

final readonly class TargetActivationAssessment
{
    /** @var list<TargetActivationBlocker> */
    public array $blockers;

    /** @param list<TargetActivationBlocker> $blockers */
    public function __construct(array $blockers)
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->value] = $blocker;
        }
        $this->blockers = array_values($unique);
    }

    public function canEnable(): bool
    {
        return [] === $this->blockers;
    }
}
