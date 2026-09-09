<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveTaskStreamScanStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Failed = 'failed';
    case NotScannedLimit = 'not_scanned_limit';
}
