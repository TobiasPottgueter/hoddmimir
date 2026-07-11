<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

final readonly class PbsSystemJitterSource implements PbsJitterSource
{
    public function milliseconds(int $maximum): int
    {
        return random_int(0, $maximum);
    }
}
