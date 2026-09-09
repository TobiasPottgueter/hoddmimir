<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingMutationResult
{
    public function __construct(
        public OnboardingMutationStatus $status,
        public string $connectionId,
        public ?int $revision,
        public ?OnboardingVerification $verification = null,
    ) {
        if (16 !== strlen($connectionId)) {
            throw new InvalidArgumentException('The onboarding mutation connection identifier is invalid.');
        }
        if (OnboardingMutationStatus::Applied === $status || OnboardingMutationStatus::Replayed === $status) {
            if (null === $revision) {
                throw new InvalidArgumentException('The successful onboarding mutation result is invalid.');
            }
            if (null === $verification) {
                throw new InvalidArgumentException('The successful onboarding mutation result is invalid.');
            }
            if (!$verification->passed()) {
                throw new InvalidArgumentException('The successful onboarding mutation result is invalid.');
            }
            return;
        }
        if (OnboardingMutationStatus::Conflict === $status) {
            if (null === $revision) {
                throw new InvalidArgumentException('The conflicting onboarding mutation result is invalid.');
            }
            if (null !== $verification) {
                throw new InvalidArgumentException('The conflicting onboarding mutation result is invalid.');
            }
            return;
        }
        if (null !== $revision) {
            throw new InvalidArgumentException('The rejected onboarding mutation result is invalid.');
        }
        if (null === $verification) {
            throw new InvalidArgumentException('The rejected onboarding mutation result is invalid.');
        }
        if ($verification->passed()) {
            throw new InvalidArgumentException('The rejected onboarding mutation result is invalid.');
        }
    }
}
