<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pbs\PbsContentClient;

interface PbsContentClientFactory
{
    public function createContentClient(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsContentClient;
}
