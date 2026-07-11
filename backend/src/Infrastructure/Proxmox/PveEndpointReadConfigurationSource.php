<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\EndpointId;

interface PveEndpointReadConfigurationSource
{
    public function load(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
    ): PveEndpointReadConfiguration;
}
