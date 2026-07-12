<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pbs\PbsReadConnector;

interface PbsReadConnectorFactory
{
    public function create(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsReadConnector;
}
