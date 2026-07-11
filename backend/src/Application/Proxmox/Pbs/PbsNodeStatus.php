<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsNodeStatus
{
    public function __construct(
        public string $node,
        public int $uptimeSeconds,
        public int $memoryTotalBytes,
        public int $memoryUsedBytes,
        public int $rootTotalBytes,
        public int $rootUsedBytes,
        public int $rootAvailableBytes,
    ) {
    }
}
