<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

interface PbsReadClient
{
    public function version(): PbsVersion;

    /** @return list<string> */
    public function nodeNames(): array;

    public function permission(string $path): PbsEffectivePermission;

    public function nodeStatus(string $node): PbsNodeStatus;

    public function instanceIdentity(string $node): PbsInstanceIdentity;

    public function datastoreConfigurations(): PbsDatastoreConfigurationSnapshot;

    /** @return list<PbsDatastoreDefinition> */
    public function datastores(): array;

    public function datastoreStatus(PbsDatastoreId $id, PbsDatastoreBackendType $backendType): PbsDatastoreCapacity;
}
