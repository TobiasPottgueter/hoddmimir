<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

interface PbsRetryDelay
{
    public function pause(int $attempt): void;
}
