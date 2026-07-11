<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsEffectivePermission
{
    /** @param array<string, bool> $privileges */
    public function __construct(public string $path, public array $privileges)
    {
    }

    public function grants(string $privilege): bool
    {
        return array_key_exists($privilege, $this->privileges);
    }

    public function propagates(string $privilege): bool
    {
        return true === ($this->privileges[$privilege] ?? null);
    }
}
