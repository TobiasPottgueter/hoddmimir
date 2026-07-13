<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

final readonly class PromoteEligibleShadowDecision
{
    public function __construct(private BackupQueueStore $store)
    {
    }

    public function execute(ShadowPromotion $promotion): string
    {
        return $this->store->promote($promotion);
    }
}
