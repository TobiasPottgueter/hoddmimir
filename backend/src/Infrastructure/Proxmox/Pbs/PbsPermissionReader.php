<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Infrastructure\Validation\AsciiPatternValidator;

final readonly class PbsPermissionReader
{
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
