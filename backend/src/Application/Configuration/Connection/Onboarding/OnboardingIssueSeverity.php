<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

enum OnboardingIssueSeverity: string
{
    case Warning = 'warning';
    case Error = 'error';
}
