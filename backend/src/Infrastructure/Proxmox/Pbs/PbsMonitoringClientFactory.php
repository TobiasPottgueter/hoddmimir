<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;

interface PbsMonitoringClientFactory
{
    public function createMonitoringClient(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
        PbsTasksAndJobsLimits $limits,
    ): PbsMonitoringClient;
}
