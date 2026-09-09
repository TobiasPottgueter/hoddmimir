<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsBackupType: string
{
    case Vm = 'vm';
    case Ct = 'ct';
    case Host = 'host';
}
