<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Proxmox\Pve\PveGuestType;
use InvalidArgumentException;

final readonly class PveGuestObservation
{
    public function __construct(
        public PveGuestType $type,
        public int $vmid,
        public string $node,
        public ?string $name,
        public ?bool $isTemplate,
    ) {
        if ($this->vmid < 1) {
            throw new InvalidArgumentException('The PVE guest VMID must be positive.');
        }
        if (!PveCoreTextValidator::isNodeName($this->node)) {
            throw new InvalidArgumentException('The PVE guest node name is invalid.');
        }
        if (null !== $this->name && ('' === $this->name || strlen($this->name) > 255)) {
            throw new InvalidArgumentException('The PVE guest name is invalid.');
        }
    }

    public function key(): string
    {
        return $this->type->value.':'.$this->vmid;
    }
}
