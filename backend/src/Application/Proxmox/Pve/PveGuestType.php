<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveGuestType: string
{
    case Qemu = 'qemu';
    case Lxc = 'lxc';
}
