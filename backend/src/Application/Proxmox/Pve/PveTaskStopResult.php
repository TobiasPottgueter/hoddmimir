<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveTaskStopResult
{
    private function __construct(public PveTaskStopStatus $status)
    {
    }

    public static function requested(): self
    {
        return new self(PveTaskStopStatus::Requested);
    }

    public static function ambiguous(): self
    {
        return new self(PveTaskStopStatus::Ambiguous);
    }
}
