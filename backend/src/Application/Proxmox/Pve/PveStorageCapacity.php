<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveStorageCapacity
{
    public function __construct(
        public int $totalBytes,
        public int $usedBytes,
        public int $availableBytes,
    ) {
        if ($totalBytes < 0 || $usedBytes < 0 || $availableBytes < 0) {
            throw new InvalidArgumentException('Storage capacity values must not be negative.');
        }

        if ($usedBytes > $totalBytes || $availableBytes > $totalBytes) {
            throw new InvalidArgumentException('Used and available storage capacity must not exceed total capacity.');
        }
    }
}
