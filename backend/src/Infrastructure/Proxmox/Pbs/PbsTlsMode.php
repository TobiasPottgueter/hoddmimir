<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

enum PbsTlsMode: string
{
    case SystemCa = 'system_ca';
    case CustomCa = 'custom_ca';
    case CertificateFingerprint = 'certificate_fingerprint';
}
