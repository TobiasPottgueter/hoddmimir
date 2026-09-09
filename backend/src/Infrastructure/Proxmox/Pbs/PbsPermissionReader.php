<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Infrastructure\Validation\AsciiPatternValidator;

final readonly class PbsPermissionReader
{
    private const int MAXIMUM_PATHS = 4096;
    private const int MAXIMUM_PRIVILEGES_PER_PATH = 256;
    private const int MAXIMUM_PRIVILEGES_TOTAL = 65536;

    /** @return list<PbsEffectivePermission> */
    public function readAll(PbsApiEnvelope $envelope): array
    {
        if (!$envelope->data instanceof \stdClass) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $paths = get_object_vars($envelope->data);
        if (count($paths) > self::MAXIMUM_PATHS) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $result = [];
        $totalPrivileges = 0;
        foreach (array_keys($paths) as $path) {
            if (!AsciiPatternValidator::matches('/\A\/(?:[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*)?\z/D', $path)) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            $permission = $this->read($envelope, $path);
            $totalPrivileges += count($permission->privileges);
            if ($totalPrivileges > self::MAXIMUM_PRIVILEGES_TOTAL) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            $result[] = $permission;
        }
        usort($result, static fn (PbsEffectivePermission $left, PbsEffectivePermission $right): int => $left->path <=> $right->path);
        return $result;
    }

    public function read(PbsApiEnvelope $envelope, string $path): PbsEffectivePermission
    {
        if (!$envelope->data instanceof \stdClass) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $privileges = $envelope->data->{$path} ?? null;
        if (null === $privileges) {
            return new PbsEffectivePermission($path, []);
        }
        if (!$privileges instanceof \stdClass) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        if (count(get_object_vars($privileges)) > self::MAXIMUM_PRIVILEGES_PER_PATH) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        /** @var array<string, bool> $result */
        $result = [];
        foreach (get_object_vars($privileges) as $name => $propagates) {
            if (!AsciiPatternValidator::matches('/\A[A-Za-z][A-Za-z.]+\z/D', $name) || !is_bool($propagates)) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            $result[(string) $name] = $propagates;
        }
        ksort($result, SORT_STRING);
        return new PbsEffectivePermission($path, $result);
    }
}
