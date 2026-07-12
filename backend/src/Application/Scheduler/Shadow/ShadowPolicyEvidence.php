<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Inventory\InventoryIdentifier;
use InvalidArgumentException;

final readonly class ShadowPolicyEvidence
{
    public function __construct(
        public InventoryIdentifier $policyId,
        public int $revision,
        private string $snapshotHash,
    ) {
        if ($this->revision < 1) {
            throw new InvalidArgumentException('A shadow policy revision must be positive.');
        }
        if (32 !== strlen($this->snapshotHash)) {
            throw new InvalidArgumentException('A shadow policy snapshot hash must contain exactly 32 bytes.');
        }
    }

    public function snapshotHash(): string
    {
        return $this->snapshotHash;
    }

    public function snapshotHashHex(): string
    {
        return bin2hex($this->snapshotHash);
    }
}
