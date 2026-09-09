<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

final readonly class PveTlsConfiguration
{
    private function __construct(
        public PveTlsMode $mode,
        public ?PveCustomCaCertificate $customCa,
        public ?PveCertificateFingerprint $certificateFingerprint,
    ) {
    }

    public static function systemCa(): self
    {
        return new self(PveTlsMode::SystemCa, null, null);
    }

    public static function customCa(PveCustomCaCertificate $customCa): self
    {
        return new self(PveTlsMode::CustomCa, $customCa, null);
    }

    public static function certificateFingerprint(PveCertificateFingerprint $fingerprint): self
    {
        return new self(PveTlsMode::CertificateFingerprint, null, $fingerprint);
    }
}
