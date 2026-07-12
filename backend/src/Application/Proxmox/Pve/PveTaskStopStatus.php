<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveTaskStopStatus: string
{
    case Requested = 'requested';
    case Ambiguous = 'ambiguous';
}
