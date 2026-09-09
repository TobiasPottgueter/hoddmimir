<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveNodeStorageStatus
{
    public function __construct(
        public string $node,
        public string $storageId,
        public string $storageType,
        public PveStorageContentSet $content,
        public bool $enabled,
        public bool $active,
        public bool $shared,
        public PveStorageCapacityState $capacityState,
        public ?PveStorageCapacity $capacity,
    ) {
        if ('' === $node || '' === $storageType) {
            throw new InvalidArgumentException('Node storage status identifiers must not be empty.');
        }

        if (!PveStorageIdValidator::isValid($storageId)) {
            throw new InvalidArgumentException('The node storage ID does not match the PVE storage ID grammar.');
        }

        if (PveStorageCapacityState::Fresh === $capacityState && null === $capacity) {
            throw new InvalidArgumentException('Fresh storage capacity requires all capacity values.');
        }

        if (PveStorageCapacityState::Fresh !== $capacityState && null !== $capacity) {
            throw new InvalidArgumentException('Non-fresh storage capacity must not expose capacity values.');
        }
    }
}
