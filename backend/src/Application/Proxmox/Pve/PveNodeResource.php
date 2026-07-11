<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveNodeResource
{
    public function __construct(
        public string $name,
        public ?string $status,
    ) {
    }
}
