<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveMissingPermission
{
    public function __construct(public PveRequiredPermission $permission)
    {
    }

    public function path(): string
    {
        return $this->permission->path();
    }

    public function privilege(): string
    {
        return $this->permission->value;
    }
}
