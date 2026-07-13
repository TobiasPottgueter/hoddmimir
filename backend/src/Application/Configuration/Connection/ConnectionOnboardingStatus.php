<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection;

enum ConnectionOnboardingStatus: string
{
    case FirstAutomaticScanPending = 'first_automatic_scan_pending';
    case InventoryVerified = 'inventory_verified';
    case InventoryPartial = 'inventory_partial';
    case InventoryFailed = 'inventory_failed';
}
