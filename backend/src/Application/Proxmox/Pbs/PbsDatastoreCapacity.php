<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsDatastoreCapacity
{
    public PbsCapacitySemantics $semantics;

    public function __construct(
        public PbsDatastoreId $id,
        public PbsDatastoreBackendType $backendType,
        public int $totalBytes,
        public int $usedBytes,
        public int $availableBytes,
    ) {
        $this->semantics = PbsDatastoreBackendType::S3 === $backendType
            ? PbsCapacitySemantics::LocalCache
            : PbsCapacitySemantics::DatastoreFilesystem;
    }

    public function maySatisfyTargetFreeSpaceGate(): bool
    {
        return PbsCapacitySemantics::DatastoreFilesystem === $this->semantics;
    }
}
