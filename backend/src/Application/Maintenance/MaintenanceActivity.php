<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

enum MaintenanceActivity
{
    case ApplicationWrite;
    case CollectInventory;
    case MonitorBackups;
}
