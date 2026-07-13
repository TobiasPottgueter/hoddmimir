<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveExecutorPermissionReader
{
    public function read(mixed $data, string $expectedPath): PveExecutorEffectivePermission
    {
        if ($data instanceof \stdClass) {
            $data = \get_object_vars($data);
        }
        if (!\is_array($data)) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
        if (\array_is_list($data)) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
        $permissions = $data[$expectedPath] ?? null;
        if ($permissions instanceof \stdClass) {
            $permissions = \get_object_vars($permissions);
        }
        if (!\is_array($permissions)) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
        if (\array_is_list($permissions)) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
        $normalized = [];
        foreach ($permissions as $name => $value) {
            if (\is_string($name) && '' !== $name && \is_int($value) && \in_array($value, [0, 1], true)) {
                $normalized[$name] = $value;
            }
        }

        return new PveExecutorEffectivePermission($expectedPath, $normalized);
    }
}
