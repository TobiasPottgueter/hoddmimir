<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PvePermissionAssessment
{
    /** @param list<PveMissingPermission> $missing */
    public function __construct(public array $missing)
    {
    }

    public function isComplete(): bool
    {
        return [] === $this->missing;
    }
}
