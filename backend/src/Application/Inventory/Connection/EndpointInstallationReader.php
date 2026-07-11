<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;

/**
 * Infrastructure resolves endpoint and credential details from the opaque IDs.
 * Neither those details nor secret-bearing failures may cross this port.
 */
interface EndpointInstallationReader
{
    /** @throws EndpointReadFailure */
    public function read(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        ProxmoxProduct $product,
        ConnectionReadCheckpoint $checkpoint,
    ): PveInstallationSnapshot|PbsInstallationSnapshot;
}
