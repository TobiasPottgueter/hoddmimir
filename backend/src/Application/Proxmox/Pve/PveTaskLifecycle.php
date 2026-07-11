<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveTaskLifecycle: string
{
    case Running = 'running';
    case Stopped = 'stopped';
}
