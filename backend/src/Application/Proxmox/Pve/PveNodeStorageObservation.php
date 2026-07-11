<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use DateTimeImmutable;
use DateTimeZone;

final readonly class PveNodeStorageObservation
{
    public DateTimeImmutable $observedAt;

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
        DateTimeImmutable $observedAt,
    ) {
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function fromStatus(PveNodeStorageStatus $status, DateTimeImmutable $observedAt): self
    {
        return new self(
            $status->node,
            $status->storageId,
            $status->storageType,
            $status->content,
            $status->enabled,
            $status->active,
            $status->shared,
            $status->capacityState,
            $status->capacity,
            $observedAt,
        );
    }
}
