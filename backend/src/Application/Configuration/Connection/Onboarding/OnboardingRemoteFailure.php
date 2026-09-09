<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use RuntimeException;

final class OnboardingRemoteFailure extends RuntimeException
{
    public function __construct(public readonly OnboardingIssueCode $failureCode, public readonly ?OnboardingCredentialKind $credential = null)
    {
        parent::__construct('The read-only Proxmox onboarding verification failed.');
    }
}
