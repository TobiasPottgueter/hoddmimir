<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsSyncDirection: string
{
    case Pull = 'pull';
    case Push = 'push';
}
