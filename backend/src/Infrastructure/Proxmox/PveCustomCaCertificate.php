<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Infrastructure\Validation\PemCertificateBundleNormalizer;
use InvalidArgumentException;

final readonly class PveCustomCaCertificate
{
    private function __construct(public string $pem)
    {
    }

    public static function fromPem(string $pem): self
    {
        $normalized = PemCertificateBundleNormalizer::normalize($pem);
        if (null === $normalized) {
            throw new InvalidArgumentException('The custom CA bundle is invalid.');
        }

        return new self($normalized);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->pem);
    }
}
