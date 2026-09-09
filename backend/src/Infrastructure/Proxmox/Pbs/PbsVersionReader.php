<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Infrastructure\Validation\AsciiPatternValidator;

final readonly class PbsVersionReader
{
    public function read(PbsApiEnvelope $envelope): PbsVersion
    {
        $data = $envelope->data;
        if (!$data instanceof \stdClass) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $version = $data->version ?? null;
        $release = $data->release ?? null;
        $repoId = $data->repoid ?? null;
        if (!is_string($version) || !is_string($release) || !is_string($repoId)
            || !AsciiPatternValidator::matches('/\A([0-9]+)\.([0-9]+)(?:\.([0-9]+))?\z/D', $version)
            || !AsciiPatternValidator::matches('/\A[0-9A-Za-z.+~_-]{1,64}\z/D', $release)
            || !AsciiPatternValidator::matches('/\A[0-9A-Fa-f]{8,64}\z/D', $repoId)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $parts = explode('.', $version);
        $major = (int) $parts[0];
        if ($major < 3 || $major > 4) {
            throw PbsReadFailure::for(PbsReadFailureCode::UnsupportedVersion);
        }
        return new PbsVersion($major, (int) $parts[1], isset($parts[2]) ? (int) $parts[2] : null, $version, $release, $repoId);
    }
}
