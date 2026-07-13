<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

interface PveBackupClientProvider
{
    public function forRequest(string $requestId): PveBackupClient;
}
