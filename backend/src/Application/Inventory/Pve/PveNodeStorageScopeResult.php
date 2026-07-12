<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use InvalidArgumentException;

final readonly class PveNodeStorageScopeResult
{
    public function __construct(
        public string $node,
        public InventoryScopeStatus $status,
    ) {
        if (!PveCoreTextValidator::isNodeName($node)) {
            throw new InvalidArgumentException('The PVE node-storage scope key is invalid.');
        }
    }

    public function isComplete(): bool
    {
        return InventoryScopeStatus::Complete === $this->status;
    }
}
