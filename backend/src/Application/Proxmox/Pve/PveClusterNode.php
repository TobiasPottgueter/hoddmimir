<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveClusterNode
{
    public function __construct(
        public string $name,
        public ?bool $online,
        public ?int $localNodeId,
        public ?bool $local,
    ) {
    }
}
