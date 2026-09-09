<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveVersion;
use App\Infrastructure\Validation\AsciiPatternValidator;

final readonly class PveVersionReader
{
    public function read(mixed $data): PveVersion
    {
        if ($data instanceof \stdClass) {
            $data = get_object_vars($data);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $release = $data['release'] ?? null;
        $version = $data['version'] ?? null;
        $repoId = $data['repoid'] ?? null;
        if (!is_string($release) || !is_string($version) || !is_string($repoId) || $repoId === '') {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        if (1 !== preg_match('/\A([0-9]+)\.([0-9]+)\z/D', $release, $releaseParts)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $major = (int) $releaseParts[1];
        $minor = (int) $releaseParts[2];
        $versionPattern = sprintf('/\A%d\.%d(?:\.([0-9]+))?(?:[-+~][A-Za-z0-9.+~_-]+)?\z/D', $major, $minor);
        if (1 !== preg_match($versionPattern, $version, $versionParts)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        if ($major < 7) {
            throw PveReadFailure::for(PveReadFailureCode::UnsupportedVersion);
        }

        if ($major > 9) {
            throw PveReadFailure::for(PveReadFailureCode::UnsupportedVersion);
        }

        if ($major >= 8) {
            $repoIdMatches = AsciiPatternValidator::matches('/\A[0-9A-Fa-f]{8,64}\z/D', $repoId);
            if (!$repoIdMatches) {
                throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
            }
        }

        return new PveVersion(
            $major,
            $minor,
            isset($versionParts[1]) ? (int) $versionParts[1] : null,
            $release,
            $version,
            $repoId,
        );
    }
}
