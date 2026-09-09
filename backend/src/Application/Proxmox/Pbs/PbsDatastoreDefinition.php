<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsDatastoreDefinition
{
    public function __construct(
        public PbsDatastoreId $id,
        public PbsDatastoreBackendType $backendType,
        public PbsMountStatus $mountStatus,
        public ?PbsMaintenanceMode $maintenanceMode,
    ) {
    }

    public function allowsBackupWrites(): bool
    {
        return $this->mountStatus->isAvailable() && null === $this->maintenanceMode;
    }
}
