<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pve\PveReadConnector;

interface PveCoreReadConnectorFactory
{
    public function create(
        PveEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PveReadConnector;
}
