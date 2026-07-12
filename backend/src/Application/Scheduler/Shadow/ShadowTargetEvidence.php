<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Inventory\InventoryIdentifier;
use InvalidArgumentException;

final readonly class ShadowTargetEvidence
{
    public function __construct(
        public InventoryIdentifier $targetId,
        public int $revision,
    ) {
        if ($this->revision < 1) {
            throw new InvalidArgumentException('A shadow target revision must be positive.');
        }
    }
}
