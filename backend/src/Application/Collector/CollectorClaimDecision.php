<?php

declare(strict_types=1);

namespace App\Application\Collector;

use DateTimeImmutable;

final readonly class CollectorClaimDecision
{
    private function __construct(
        public ?CollectorLease $lease,
        public ?DateTimeImmutable $scheduledFor,
        public DateTimeImmutable $databaseNow,
        public DateTimeImmutable $retryAt,
    ) {
    }

    public static function claimed(
        CollectorLease $lease,
        DateTimeImmutable $scheduledFor,
        DateTimeImmutable $databaseNow,
    ): self {
        return new self($lease, $scheduledFor, $databaseNow, $lease->expiresAt);
    }

    public static function waiting(DateTimeImmutable $databaseNow, DateTimeImmutable $retryAt): self
    {
        return new self(null, null, $databaseNow, $retryAt);
    }

    public function isClaimed(): bool
    {
        return null !== $this->lease;
    }
}
