<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsMaintenanceMode: string
{
    case ReadOnly = 'read-only';
    case Offline = 'offline';
    case Delete = 'delete';
    case Unmount = 'unmount';
    case S3Refresh = 's3-refresh';
}
