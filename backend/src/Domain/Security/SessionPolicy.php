<?php

declare(strict_types=1);

namespace App\Domain\Security;

use DateTimeImmutable;
use DomainException;

final readonly class SessionPolicy
{
    public const int IDLE_SECONDS = 1800;
    public const int ABSOLUTE_SECONDS = 43200;
    public function issue(DateTimeImmutable $now): SessionWindow
    {
        return new SessionWindow($now, $now, $now->modify('+1800 seconds'), $now->modify('+43200 seconds'));
    }
    public function touch(SessionWindow $window, DateTimeImmutable $now): SessionWindow
    {
        if ($window->isExpiredAt($now) || $now < $window->lastSeenAt) {
            throw new DomainException('An expired or time-regressed session cannot be touched.');
        }
        $idle = $now->modify('+1800 seconds');
        if ($idle > $window->absoluteExpiresAt) {
            $idle = $window->absoluteExpiresAt;
        }
        return new SessionWindow($window->issuedAt, $now, $idle, $window->absoluteExpiresAt);
    }
}
