<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

enum OnboardingProduct: string
{
    case Pve = 'pve';
    case Pbs = 'pbs';

    public function defaultPort(): int
    {
        return self::Pve === $this ? 8006 : 8007;
    }
}
