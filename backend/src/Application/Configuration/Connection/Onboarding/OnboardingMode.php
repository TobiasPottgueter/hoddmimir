<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

enum OnboardingMode: string
{
    case Activate = 'activate';
    case Rotate = 'rotate';
    case EndpointAdd = 'endpoint_add';
    case EndpointUpdate = 'endpoint_update';
}
