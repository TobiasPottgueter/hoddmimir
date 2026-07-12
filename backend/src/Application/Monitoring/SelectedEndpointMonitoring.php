<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Collector\CollectorLease;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\InventoryIdentifier;

interface SelectedEndpointMonitoring
{
    public function execute(
        CollectorLease $lease,
        InventoryIdentifier $parentRunId,
        ConnectionInstallationRead $read,
        ConnectionReadCheckpoint $checkpoint,
    ): ConnectionMonitoringResult;
}
