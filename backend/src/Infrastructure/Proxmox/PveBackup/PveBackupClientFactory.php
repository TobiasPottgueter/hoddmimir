<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveVersion;

interface PveBackupClientFactory
{
    public function create(PveBackupEndpointConfiguration $configuration, PveVersion $version): PveBackupClient;
}
