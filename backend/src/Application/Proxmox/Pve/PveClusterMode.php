<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveClusterMode: string
{
    case Clustered = 'clustered';
    case Standalone = 'standalone';
}
