<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveTaskSource: string
{
    case Active = 'active';
    case Archive = 'archive';
}
