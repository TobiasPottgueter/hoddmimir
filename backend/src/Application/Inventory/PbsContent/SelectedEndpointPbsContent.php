<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Collector\CollectorLease;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\InventoryIdentifier;

interface SelectedEndpointPbsContent
{
    public function execute(
        CollectorLease $lease,
        InventoryIdentifier $parentRunId,
        ConnectionInstallationRead $read,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsContentRunStatus;
}
