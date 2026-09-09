<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveBackupMode: string
{
    case Snapshot = 'snapshot';
    case Suspend = 'suspend';
    case Stop = 'stop';
}
