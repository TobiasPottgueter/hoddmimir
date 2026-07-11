<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreCapacity;
use App\Application\Proxmox\Pbs\PbsDatastoreConfigurationSnapshot;
use App\Application\Proxmox\Pbs\PbsDatastoreDefinition;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsInstanceIdentity;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsReadClient;
use App\Application\Proxmox\Pbs\PbsVersion;

final readonly class PbsHttpReadClient implements PbsReadClient
{
    public function __construct(
        private PbsApiTransport $transport,
        private PbsNodesReader $nodesReader,
        private PbsPermissionReader $permissionReader,
        private PbsNodeStatusReader $nodeStatusReader,
        private PbsInstanceIdentityReader $identityReader,
        private PbsDatastoreConfigurationReader $configurationReader,
        private PbsDatastoreListReader $datastoreListReader,
        private PbsDatastoreStatusReader $datastoreStatusReader,
        private PbsVersion $connectedVersion,
    ) {}

    public function version(): PbsVersion { return $this->connectedVersion; }
    public function nodeNames(): array { return $this->nodesReader->read($this->transport->get(PbsRequest::nodes())); }
    public function permission(string $path): PbsEffectivePermission
    {
        return $this->permissionReader->read($this->transport->get(PbsRequest::permission($path)), $path);
    }
    public function nodeStatus(string $node): PbsNodeStatus
    {
        return $this->nodeStatusReader->read($this->transport->get(PbsRequest::nodeStatus($node)), $node);
    }
    public function instanceIdentity(string $node): PbsInstanceIdentity
    {
        return $this->identityReader->read($this->transport->get(PbsRequest::instanceIdentity($node)));
    }
    public function datastoreConfigurations(): PbsDatastoreConfigurationSnapshot
    {
        return $this->configurationReader->read($this->transport->get(PbsRequest::datastoreConfigurations()));
    }
    public function datastores(): array
    {
        return $this->datastoreListReader->read($this->transport->get(PbsRequest::datastores()), $this->connectedVersion);
    }
    public function datastoreStatus(PbsDatastoreId $id, PbsDatastoreBackendType $backendType): PbsDatastoreCapacity
    {
        return $this->datastoreStatusReader->read(
            $this->transport->get(PbsRequest::datastoreStatus($id)),
            $id,
            $backendType,
            $this->connectedVersion,
        );
    }
}
