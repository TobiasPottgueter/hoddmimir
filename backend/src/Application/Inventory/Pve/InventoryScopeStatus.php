<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

enum InventoryScopeStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Failed = 'failed';
}
