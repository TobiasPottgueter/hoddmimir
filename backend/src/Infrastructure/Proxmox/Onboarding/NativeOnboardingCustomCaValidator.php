<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingCustomCaValidator;
use App\Infrastructure\Validation\PemCertificateBundleNormalizer;

final readonly class NativeOnboardingCustomCaValidator implements OnboardingCustomCaValidator
{
    public function accepts(string $pem): bool
    {
        return null !== PemCertificateBundleNormalizer::normalize($pem);
    }
}
