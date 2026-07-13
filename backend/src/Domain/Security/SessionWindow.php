<?php

declare(strict_types=1);

namespace App\Domain\Security;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SessionWindow
{
    public DateTimeImmutable $issuedAt;
    public DateTimeImmutable $lastSeenAt;
    public DateTimeImmutable $idleExpiresAt;
    public DateTimeImmutable $absoluteExpiresAt;
    public function __construct(DateTimeImmutable $issuedAt, DateTimeImmutable $lastSeenAt, DateTimeImmutable $idleExpiresAt, DateTimeImmutable $absoluteExpiresAt)
    {
        $utc = new DateTimeZone('UTC');
        $this->issuedAt = $issuedAt->setTimezone($utc);
        $this->lastSeenAt = $lastSeenAt->setTimezone($utc);
        $this->idleExpiresAt = $idleExpiresAt->setTimezone($utc);
        $this->absoluteExpiresAt = $absoluteExpiresAt->setTimezone($utc);
        if ($this->issuedAt > $this->lastSeenAt || $this->lastSeenAt >= $this->idleExpiresAt || $this->idleExpiresAt > $this->absoluteExpiresAt) {
            throw new InvalidArgumentException('The session window is invalid.');
        }
    }
    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        return $now >= $this->idleExpiresAt || $now >= $this->absoluteExpiresAt;
    }
}
