<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

/** Bounded cursor outcome for one node/source stream. */
final readonly class PveTaskStreamScanResult
{
    public function __construct(
        public string $node,
        public PveTaskSource $source,
        public PveTaskStreamScanStatus $status,
        public int $requests,
        public int $rawRows,
    ) {
        if (!PveTaskNodeNameValidator::isValid($node) || $requests < 0 || $rawRows < 0
            || (PveTaskStreamScanStatus::Complete === $status && 0 === $requests)
            || (PveTaskStreamScanStatus::NotScannedLimit === $status && (0 !== $requests || 0 !== $rawRows))) {
            throw new InvalidArgumentException('The PVE task stream scan result is invalid.');
        }
    }

    public function isComplete(): bool
    {
        return PveTaskStreamScanStatus::Complete === $this->status;
    }
}
