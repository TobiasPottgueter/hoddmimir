<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsReadClient;
use App\Application\Proxmox\Pbs\PbsReadConnector;

final readonly class PbsEndpointReadConnector implements PbsReadConnector
{
    public function __construct(
        private PbsApiTransport $transport,
        private PbsVersionReader $versionReader,
        private PbsPingReader $pingReader,
        private PbsNodesReader $nodesReader,
        private PbsPermissionReader $permissionReader,
        private PbsNodeStatusReader $nodeStatusReader,
        private PbsInstanceIdentityReader $identityReader,
        private PbsDatastoreConfigurationReader $configurationReader,
        private PbsDatastoreListReader $datastoreListReader,
        private PbsDatastoreStatusReader $datastoreStatusReader,
    ) {}

    public function connect(): PbsReadClient
    {
        $version = $this->versionReader->read($this->transport->get(PbsRequest::version()));
        $this->pingReader->assertPbs($this->transport->get(PbsRequest::ping()));
        return new PbsHttpReadClient(
            $this->transport,
            $this->nodesReader,
            $this->permissionReader,
            $this->nodeStatusReader,
            $this->identityReader,
            $this->configurationReader,
            $this->datastoreListReader,
            $this->datastoreStatusReader,
            $version,
        );
    }
}
