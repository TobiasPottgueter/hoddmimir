<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;

interface PbsInventoryMapper
{
    public function map(
        InventoryIdentifier $runId,
        ConnectionInstallationRead $read,
        DateTimeImmutable $observedAt,
    ): PbsInventoryCommit;
}
