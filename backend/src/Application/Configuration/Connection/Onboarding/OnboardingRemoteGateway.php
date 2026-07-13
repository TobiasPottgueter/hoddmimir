<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

interface OnboardingRemoteGateway
{
    /** Performs only the documented read-only GET probes. */
    public function verify(OnboardingActivationCommand $command): OnboardingRemoteEvidence;
}
