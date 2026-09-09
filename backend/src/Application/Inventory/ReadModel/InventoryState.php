<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

enum InventoryState: string
{
    case Active = 'active';
    case Archived = 'archived';
}
