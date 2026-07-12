<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsTaskStreamStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Failed = 'failed';
}
