<?php

declare(strict_types=1);

namespace App\Application\Collector;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CollectorLeaseState
{
    public function __construct(
        public int $lastFencingToken = 0,
        public ?CollectorLease $activeLease = null,
    ) {
        if ($this->lastFencingToken < 0) {
            throw new InvalidArgumentException('The last collector fencing token must not be negative.');
        }

        if (null !== $this->activeLease && $this->activeLease->fencingToken !== $this->lastFencingToken) {
            throw new InvalidArgumentException('The active collector lease must use the last fencing token.');
        }
    }

    public function claim(
        CollectorWorkerId $ownerId,
        CollectorCycleToken $token,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): self {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('The collector lease TTL must be at least one second.');
        }

        if (null !== $this->activeLease && !$this->activeLease->isExpiredAt($now)) {
            throw new CollectorLeaseUnavailable('The collector cycle is already leased.');
        }

        $fencingToken = $this->lastFencingToken + 1;
        $expiresAt = $now
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify(sprintf('+%d seconds', $ttlSeconds));

        return new self(
            $fencingToken,
            new CollectorLease($ownerId, $token, $fencingToken, $expiresAt),
        );
    }

    public function renew(
        CollectorWorkerId $ownerId,
        CollectorCycleToken $token,
        int $fencingToken,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): self {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('The collector lease TTL must be at least one second.');
        }

        $lease = $this->ownedActiveLease($ownerId, $token, $fencingToken, $now);
        $expiresAt = $now
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify(sprintf('+%d seconds', $ttlSeconds));

        if ($expiresAt <= $lease->expiresAt) {
            throw new InvalidArgumentException('A collector lease renewal must extend its expiry.');
        }

        return new self(
            $this->lastFencingToken,
            new CollectorLease($ownerId, $token, $fencingToken, $expiresAt),
        );
    }

    public function release(
        CollectorWorkerId $ownerId,
        CollectorCycleToken $token,
        int $fencingToken,
        DateTimeImmutable $now,
    ): self {
        $this->ownedActiveLease($ownerId, $token, $fencingToken, $now);

        return new self($this->lastFencingToken);
    }

    private function ownedActiveLease(
        CollectorWorkerId $ownerId,
        CollectorCycleToken $token,
        int $fencingToken,
        DateTimeImmutable $now,
    ): CollectorLease {
        if (
            null === $this->activeLease
            || !$this->activeLease->isOwnedBy($ownerId, $token, $fencingToken)
            || $this->activeLease->isExpiredAt($now)
        ) {
            throw new CollectorLeaseOwnershipLost('The collector lease is no longer owned by this worker.');
        }

        return $this->activeLease;
    }
}
