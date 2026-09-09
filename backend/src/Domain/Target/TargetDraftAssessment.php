<?php

declare(strict_types=1);

namespace App\Domain\Target;

final readonly class TargetDraftAssessment
{
    /** @var non-empty-list<TargetDraftBlockerCode> */
    public array $blockers;

    /** @param non-empty-list<TargetDraftBlockerCode> $blockers */
    private function __construct(array $blockers)
    {
        $this->blockers = $blockers;
    }

    public static function assessDisabled(?MinimumFreeBytes $minimumFreeBytes): self
    {
        $blockers = [TargetDraftBlockerCode::TargetDisabled];
        if (null === $minimumFreeBytes) {
            $blockers[] = TargetDraftBlockerCode::MinimumFreeBytesUnconfigured;
        }

        $blockers[] = TargetDraftBlockerCode::ConcurrencyPolicyUnconfigured;

        return new self($blockers);
    }

    public function canEnable(): bool
    {
        return false;
    }
}
