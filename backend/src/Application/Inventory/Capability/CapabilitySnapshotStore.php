<?php

declare(strict_types=1);

namespace App\Application\Inventory\Capability;

use App\Application\Collector\CollectorLease;
use App\Application\Inventory\InventoryIdentifier;

interface CapabilitySnapshotStore
{
    public function persist(
        CollectorLease $lease,
        CapabilitySnapshotObservation $observation,
    ): InventoryIdentifier;
}
