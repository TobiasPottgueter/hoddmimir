<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

interface PveExecutorAclClient
{
    public function permission(string $path): PveExecutorEffectivePermission;
}
