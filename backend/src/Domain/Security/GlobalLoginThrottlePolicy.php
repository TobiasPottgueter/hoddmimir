<?php

declare(strict_types=1);

namespace App\Domain\Security;

use DateTimeImmutable;

final readonly class GlobalLoginThrottlePolicy
{
    public const int MAX_FAILURES = 1000;
    public const int WINDOW_SECONDS = 900;
    public const int LOCK_SECONDS = 900;

    public function recordFailure(GlobalLoginThrottleState $state, DateTimeImmutable $now): GlobalLoginThrottleState
    {
        if ($state->isLockedAt($now)) {
            return $state;
        }
        if ($now >= $state->windowStartedAt->modify('+'.self::WINDOW_SECONDS.' seconds')) {
            return new GlobalLoginThrottleState($now, 1, $now, null);
        }

        $count = $state->failureCount + 1;

        return new GlobalLoginThrottleState(
            $state->windowStartedAt,
            $count,
            $now,
            self::MAX_FAILURES === $count ? $now->modify('+'.self::LOCK_SECONDS.' seconds') : null,
        );
    }
}
