<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

final readonly class PveSystemJitterSource implements PveJitterSource
{
    public function seconds(): int
    {
        return random_int(0, 1);
    }
}
