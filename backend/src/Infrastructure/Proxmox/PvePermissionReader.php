<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveMissingPermission;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveRequiredPermission;

final readonly class PvePermissionReader
{
    public function read(mixed $data): PvePermissionAssessment
    {
        if ($data instanceof \stdClass) {
            $data = get_object_vars($data);
        } elseif (!is_array($data) || array_is_list($data)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $permissionsByPath = [];
        foreach ($data as $path => $permissions) {
            if ($permissions instanceof \stdClass) {
                $permissions = get_object_vars($permissions);
            }

            if (!is_string($path) || !is_array($permissions)) {
                continue;
            }

            $normalizedPath = '/' === $path ? '/' : rtrim($path, '/');
            $isAbsolute = $normalizedPath !== '' && $normalizedPath[0] === '/';
            if (!$isAbsolute) {
                continue;
            }

            $permissionsByPath[$normalizedPath] = $permissions;
        }

        $missing = [];
        foreach (PveRequiredPermission::cases() as $requiredPermission) {
            if (!$this->hasEffectivePermission($permissionsByPath, $requiredPermission)) {
                $missing[] = new PveMissingPermission($requiredPermission);
            }
        }

        return new PvePermissionAssessment($missing);
    }

    /** @param array<string, array<array-key, mixed>> $permissionsByPath */
    private function hasEffectivePermission(array $permissionsByPath, PveRequiredPermission $requiredPermission): bool
    {
        $value = $permissionsByPath[$requiredPermission->path()][$requiredPermission->value] ?? null;
        return 1 === $value;
    }
}
