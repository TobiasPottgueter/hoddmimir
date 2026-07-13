<?php

declare(strict_types=1);

namespace App\Domain\Security;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class GlobalLoginThrottleState
{
    public DateTimeImmutable $windowStartedAt;
    public DateTimeImmutable $lastFailedAt;
    public ?DateTimeImmutable $lockedUntil;

    public function __construct(
        DateTimeImmutable $windowStartedAt,
        public int $failureCount,
        DateTimeImmutable $lastFailedAt,
        ?DateTimeImmutable $lockedUntil,
    ) {
        $utc = new DateTimeZone('UTC');
        $this->windowStartedAt = $windowStartedAt->setTimezone($utc);
        $this->lastFailedAt = $lastFailedAt->setTimezone($utc);
        $this->lockedUntil = $lockedUntil?->setTimezone($utc);

        if ($failureCount < 0 || $failureCount > GlobalLoginThrottlePolicy::MAX_FAILURES
            || $this->lastFailedAt < $this->windowStartedAt
            || $this->lastFailedAt >= $this->windowStartedAt->modify('+'.GlobalLoginThrottlePolicy::WINDOW_SECONDS.' seconds')
            || ($failureCount < GlobalLoginThrottlePolicy::MAX_FAILURES) !== (null === $this->lockedUntil)) {
            throw new InvalidArgumentException('The global login throttle state is invalid.');
        }
    }

    public function isLockedAt(DateTimeImmutable $now): bool
    {
        return null !== $this->lockedUntil
            && $now->setTimezone(new DateTimeZone('UTC')) < $this->lockedUntil;
    }
}
