<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreDefinition;
use App\Application\Proxmox\Pbs\PbsMaintenanceMode;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use InvalidArgumentException;

final readonly class PbsDatastoreObservation
{
    public function __construct(
        public string $id,
        public PbsDatastoreBackendType $backendType,
        public PbsMountStatus $mountStatus,
        public ?PbsMaintenanceMode $maintenanceMode,
        public bool $allowsBackupWrites,
    ) {
        new PbsDatastoreId($id);
        if ($allowsBackupWrites !== ($mountStatus->isAvailable() && null === $maintenanceMode)) {
            throw new InvalidArgumentException('The PBS datastore writeability evidence is inconsistent.');
        }
    }

    public static function fromDefinition(PbsDatastoreDefinition $definition): self
    {
        return new self(
            $definition->id->value,
            $definition->backendType,
            $definition->mountStatus,
            $definition->maintenanceMode,
            $definition->allowsBackupWrites(),
        );
    }
}
