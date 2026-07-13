<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

interface OnboardingCustomCaValidator
{
    public function accepts(string $pem): bool;
}
