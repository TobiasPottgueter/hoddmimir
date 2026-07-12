<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsJobKind: string
{
    case Prune = 'prune';
    case Sync = 'sync';
    case Verify = 'verify';
}
