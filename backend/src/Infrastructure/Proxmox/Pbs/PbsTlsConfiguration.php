<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

final readonly class PbsTlsConfiguration
{
    private function __construct(
        public PbsTlsMode $mode,
        public ?PbsCustomCaCertificate $customCa,
        public ?PbsCertificateFingerprint $certificateFingerprint,
    ) {}

    public static function systemCa(): self { return new self(PbsTlsMode::SystemCa, null, null); }
    public static function customCa(PbsCustomCaCertificate $ca): self { return new self(PbsTlsMode::CustomCa, $ca, null); }
    public static function certificateFingerprint(PbsCertificateFingerprint $pin): self
    {
        return new self(PbsTlsMode::CertificateFingerprint, null, $pin);
    }
}
