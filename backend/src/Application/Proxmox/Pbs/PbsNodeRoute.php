<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

/** PBS exposes local node-scoped API routes through this canonical alias. */
enum PbsNodeRoute: string
{
    case Local = 'localhost';
}
