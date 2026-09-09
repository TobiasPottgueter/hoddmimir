<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Infrastructure\Validation\CertificateFingerprintNormalizer;
use InvalidArgumentException;

final readonly class PbsCertificateFingerprint
{
    private function __construct(public string $sha256) {}

    public static function fromSha256(string $value): self
    {
        $value = CertificateFingerprintNormalizer::normalizeSha256($value);
        if (null === $value) {
            throw new InvalidArgumentException('The PBS certificate fingerprint is invalid.');
        }
        return new self($value);
    }
}
