<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveGuestResource
{
    public function __construct(
        public PveGuestType $type,
        public int $vmid,
        public string $node,
        public ?string $name,
        public ?bool $template,
        public ?string $status,
    ) {
    }

    public function identity(): string
    {
        return $this->type->value.':'.$this->vmid;
    }
}
