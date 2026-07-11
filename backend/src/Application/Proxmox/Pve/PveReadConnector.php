<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

interface PveReadConnector
{
    public function connect(): PveReadClient;
}
