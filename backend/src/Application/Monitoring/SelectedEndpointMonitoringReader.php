<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Application\Proxmox\Pve\PveBackupInventorySnapshot;
use App\Application\Proxmox\Pve\PveTaskArchiveWindow;

interface SelectedEndpointMonitoringReader
{
    /** @param list<string> $topologyNodes */
    public function readPve(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        array $topologyNodes,
        PveTaskArchiveWindow $window,
        ConnectionReadCheckpoint $checkpoint,
    ): PveBackupInventorySnapshot;

    public function readPbs(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        string $node,
        PbsTaskWindow $window,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsExternalMonitoringSnapshot;
}
