<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

enum OnboardingTlsMode: string
{
    case SystemCa = 'system_ca';
    case CustomCa = 'custom_ca';
    case Sha256Fingerprint = 'sha256_fingerprint';
}
