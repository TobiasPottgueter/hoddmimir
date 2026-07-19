<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

interface PbsReadClient
{
    public function version(): PbsVersion;

    public function permission(string $path): PbsEffectivePermission;

    public function nodeStatus(): PbsNodeStatus;

    public function instanceIdentity(): PbsInstanceIdentity;

    public function datastoreConfigurations(): PbsDatastoreConfigurationSnapshot;

    /** @return list<PbsDatastoreDefinition> */
    public function datastores(): array;

    public function datastoreStatus(PbsDatastoreId $id, PbsDatastoreBackendType $backendType): PbsDatastoreCapacity;
}
