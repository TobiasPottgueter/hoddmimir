<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

interface PbsJitterSource
{
    public function milliseconds(int $maximum): int;
}
