<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointInstallationReader;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointInstallationReader;

final readonly class DispatchingEndpointInstallationReader implements EndpointInstallationReader
{
    public function __construct(
        private PveCoreEndpointInstallationReader $pveReader,
        private PbsEndpointInstallationReader $pbsReader,
    ) {
    }

    public function read(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        ProxmoxProduct $product,
        ConnectionReadCheckpoint $checkpoint,
    ): PveInventorySnapshot|PbsInstallationSnapshot {
        if (ProxmoxProduct::Pve === $product) {
            return $this->pveReader->read(
                $connectionId,
                $endpointId,
                $expectedRevision,
                $product,
                $checkpoint,
            );
        }

        return $this->pbsReader->read(
            $connectionId,
            $endpointId,
            $expectedRevision,
            $product,
            $checkpoint,
        );
    }
}
