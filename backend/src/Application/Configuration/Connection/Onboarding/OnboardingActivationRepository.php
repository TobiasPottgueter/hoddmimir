<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use App\Application\Security\Auth\AuthenticatedPrincipal;

interface OnboardingActivationRepository
{
    /** Atomically persists endpoint, all credentials, activation state, idempotency and audit. */
    public function activate(
        OnboardingActivationCommand $command,
        OnboardingVerification $verification,
        AuthenticatedPrincipal $principal,
    ): OnboardingMutationResult;

    /** Atomically records a sanitized denied/failed attempt, idempotently. */
    public function record(
        OnboardingActivationCommand $command,
        OnboardingMutationResult $result,
        AuthenticatedPrincipal $principal,
    ): OnboardingMutationResult;
}
