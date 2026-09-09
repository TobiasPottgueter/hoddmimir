<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Infrastructure\Validation\CertificateFingerprintNormalizer;
use InvalidArgumentException;

final readonly class PveCertificateFingerprint
{
    private function __construct(public string $sha256)
    {
    }

    public static function fromSha256(string $fingerprint): self
    {
        $normalized = CertificateFingerprintNormalizer::normalizeSha256($fingerprint);
        if (null === $normalized) {
            throw new InvalidArgumentException('The PVE certificate fingerprint is invalid.');
        }

        return new self($normalized);
    }
}
