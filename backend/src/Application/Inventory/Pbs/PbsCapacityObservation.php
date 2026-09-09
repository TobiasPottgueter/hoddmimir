<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use App\Application\Proxmox\Pbs\PbsCapacitySemantics;
use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreCapacity;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use InvalidArgumentException;

final readonly class PbsCapacityObservation
{
    public function __construct(
        public string $datastoreId,
        public PbsDatastoreBackendType $backendType,
        public PbsCapacitySemantics $semantics,
        public int $totalBytes,
        public int $usedBytes,
        public int $availableBytes,
    ) {
        new PbsDatastoreId($datastoreId);
        $expectedSemantics = PbsDatastoreBackendType::S3 === $backendType
            ? PbsCapacitySemantics::LocalCache
            : PbsCapacitySemantics::DatastoreFilesystem;
        if ($semantics !== $expectedSemantics
            || min($totalBytes, $usedBytes, $availableBytes) < 0
            || $usedBytes > $totalBytes
            || $availableBytes > $totalBytes) {
            throw new InvalidArgumentException('The PBS datastore capacity evidence is invalid.');
        }
    }

    public static function fromCapacity(PbsDatastoreCapacity $capacity): self
    {
        return new self(
            $capacity->id->value,
            $capacity->backendType,
            $capacity->semantics,
            $capacity->totalBytes,
            $capacity->usedBytes,
            $capacity->availableBytes,
        );
    }
}
