<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Proxmox\Pbs\PbsDatastoreId;

interface SelectedEndpointPbsContentReader
{
    /** @param list<PbsDatastoreId> $datastores */
    public function read(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        array $datastores,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsContentSnapshot;
}
