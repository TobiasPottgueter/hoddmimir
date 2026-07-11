<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Infrastructure\Validation\PemCertificateBundleNormalizer;
use InvalidArgumentException;

final readonly class PbsCustomCaCertificate
{
    private function __construct(public string $pem) {}

    public static function fromPem(string $pem): self
    {
        $pem = PemCertificateBundleNormalizer::normalize($pem);
        if (null === $pem) {
            throw new InvalidArgumentException('The PBS custom CA bundle is invalid.');
        }
        return new self($pem);
    }

    public function fingerprint(): string { return hash('sha256', $this->pem); }
}
