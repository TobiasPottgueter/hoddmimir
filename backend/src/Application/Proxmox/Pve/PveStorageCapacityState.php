<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveStorageCapacityState: string
{
    case Fresh = 'fresh';
    case Unavailable = 'unavailable';
    case Invalid = 'invalid';
}
