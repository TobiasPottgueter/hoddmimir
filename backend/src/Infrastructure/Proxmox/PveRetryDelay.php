<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

interface PveRetryDelay
{
    public function pause(int $retryNumber): void;
}
