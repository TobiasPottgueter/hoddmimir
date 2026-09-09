<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

enum PveStorageCapacityStatus: string
{
    case Measured = 'measured';
    case Unavailable = 'unavailable';
    case Invalid = 'invalid';
}
