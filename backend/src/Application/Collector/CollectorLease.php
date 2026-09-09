<?php

declare(strict_types=1);

namespace App\Application\Collector;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CollectorLease
{
    public DateTimeImmutable $expiresAt;

    public function __construct(
        public CollectorWorkerId $ownerId,
        public CollectorCycleToken $token,
        public int $fencingToken,
        DateTimeImmutable $expiresAt,
    ) {
        if ($this->fencingToken < 1) {
            throw new InvalidArgumentException('The collector lease fencing token must be positive.');
        }

        $this->expiresAt = $expiresAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function isExpiredAt(DateTimeImmutable $instant): bool
    {
        return $this->expiresAt <= $instant;
    }

    public function isOwnedBy(
        CollectorWorkerId $ownerId,
        CollectorCycleToken $token,
        int $fencingToken,
    ): bool
    {
        return $this->ownerId->bytes === $ownerId->bytes
            && hash_equals($this->token->binary(), $token->binary())
            && $this->fencingToken === $fencingToken;
    }
}
