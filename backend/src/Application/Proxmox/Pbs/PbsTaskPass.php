<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsTaskPass: string
{
    case Running = 'running';
    case History = 'history';
}
