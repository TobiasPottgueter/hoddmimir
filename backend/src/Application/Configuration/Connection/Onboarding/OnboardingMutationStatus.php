<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

enum OnboardingMutationStatus: string
{
    case Applied = 'applied';
    case Replayed = 'replayed';
    case Conflict = 'conflict';
    case Rejected = 'rejected';
    case Denied = 'denied';
}
