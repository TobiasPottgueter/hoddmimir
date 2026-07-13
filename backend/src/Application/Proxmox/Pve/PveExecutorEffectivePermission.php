<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveExecutorEffectivePermission
{
    /** @param array<string, int> $permissions */
    public function __construct(public string $path, public array $permissions)
    {
        if ('' === $path) {
            throw new InvalidArgumentException('A PVE executor ACL path must be absolute.');
        }
        if ('/' !== $path[0]) {
            throw new InvalidArgumentException('A PVE executor ACL path must be absolute.');
        }
        foreach ($permissions as $name => $value) {
            if ('' === $name) {
                throw new InvalidArgumentException('A PVE executor permission map is invalid.');
            }
            if (!\in_array($value, [0, 1], true)) {
                throw new InvalidArgumentException('A PVE executor permission map is invalid.');
            }
        }
    }

    public function grants(string $permission): bool
    {
        return 1 === ($this->permissions[$permission] ?? null);
    }
}
