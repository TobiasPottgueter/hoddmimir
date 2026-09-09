<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

enum ProxmoxProduct: string
{
    case Pve = 'pve';
    case Pbs = 'pbs';
}
