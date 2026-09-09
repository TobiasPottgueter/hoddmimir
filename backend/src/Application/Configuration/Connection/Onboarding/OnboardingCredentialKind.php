<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

enum OnboardingCredentialKind: string
{
    case Scan = 'scan';
    case Backup = 'backup';
}
