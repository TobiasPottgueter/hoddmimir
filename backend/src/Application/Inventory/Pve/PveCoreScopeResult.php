<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

final readonly class PveCoreScopeResult
{
    public function __construct(
        public PveCoreScope $scope,
        public InventoryScopeStatus $status,
    ) {
    }

    public function isComplete(): bool
    {
        return InventoryScopeStatus::Complete === $this->status;
    }
}
