<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Proxmox\Pve\PveNodeStorageObservation;
use App\Application\Proxmox\Pve\PveStorageCapacityState;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PveNodeStorageStateObservation
{
    public DateTimeImmutable $observedAt;

    public function __construct(
        public string $node,
        public string $storageId,
        public bool $enabled,
        public bool $active,
        public bool $shared,
        public PveStorageCapacityStatus $capacityStatus,
        public ?int $totalBytes,
        public ?int $usedBytes,
        public ?int $availableBytes,
        DateTimeImmutable $observedAt,
    ) {
        if (!PveCoreTextValidator::isNodeName($node)
            || !\App\Application\Proxmox\Pve\PveStorageIdValidator::isValid($storageId)) {
            throw new InvalidArgumentException('The PVE node-storage observation identifiers are invalid.');
        }
        $hasAllCapacity = null !== $totalBytes && null !== $usedBytes && null !== $availableBytes;
        if (PveStorageCapacityStatus::Measured === $capacityStatus) {
            if (!$hasAllCapacity || $totalBytes < 0 || $usedBytes < 0 || $availableBytes < 0
                || $usedBytes > $totalBytes || $availableBytes > $totalBytes) {
                throw new InvalidArgumentException('Measured PVE storage capacity requires valid byte values.');
            }
        } elseif (null !== $totalBytes || null !== $usedBytes || null !== $availableBytes) {
            throw new InvalidArgumentException('Unavailable or invalid PVE storage capacity must not expose byte values.');
        }
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function fromReadObservation(PveNodeStorageObservation $observation): self
    {
        if (PveStorageCapacityState::Fresh === $observation->capacityState) {
            $status = PveStorageCapacityStatus::Measured;
        } elseif (PveStorageCapacityState::Unavailable === $observation->capacityState) {
            $status = PveStorageCapacityStatus::Unavailable;
        } else {
            $status = PveStorageCapacityStatus::Invalid;
        }

        return new self(
            $observation->node,
            $observation->storageId,
            $observation->enabled,
            $observation->active,
            $observation->shared,
            $status,
            $observation->capacity?->totalBytes,
            $observation->capacity?->usedBytes,
            $observation->capacity?->availableBytes,
            $observation->observedAt,
        );
    }

    public function key(): string
    {
        return $this->node."\0".$this->storageId;
    }
}
