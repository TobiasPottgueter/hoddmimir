<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;

interface PveInventoryMapper
{
    public function map(
        InventoryIdentifier $runId,
        ConnectionInstallationRead $read,
        DateTimeImmutable $observedAt,
    ): PveInventoryCommit;
}
