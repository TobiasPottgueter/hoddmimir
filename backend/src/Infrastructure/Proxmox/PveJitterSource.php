<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

interface PveJitterSource
{
    public function seconds(): int;
}
