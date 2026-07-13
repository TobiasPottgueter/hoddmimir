<?php

declare(strict_types=1);

namespace App\Domain\Security;

use DateTimeImmutable;

final readonly class LoginThrottlePolicy
{
    public const int MAX_FAILURES = 5;
    public const int WINDOW_SECONDS = 900;
    public const int LOCK_SECONDS = 900;
    public function firstFailure(DateTimeImmutable $now): LoginThrottleState
    {
        return new LoginThrottleState($now, 1, $now, null);
    }
    public function recordFailure(LoginThrottleState $state, DateTimeImmutable $now): LoginThrottleState
    {
        if ($state->isLockedAt($now)) {
            return $state;
        }
        if ($now >= $state->windowStartedAt->modify('+900 seconds')) {
            return $this->firstFailure($now);
        }
        $count = $state->failureCount + 1;
        return new LoginThrottleState($state->windowStartedAt, $count, $now, 5 === $count ? $now->modify('+900 seconds') : null);
    }
}
