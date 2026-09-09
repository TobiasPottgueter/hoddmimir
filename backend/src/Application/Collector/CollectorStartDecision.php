<?php

declare(strict_types=1);

namespace App\Application\Collector;

use DateTimeImmutable;

final readonly class CollectorStartDecision
{
    public function __construct(
        public ?CollectorActiveCycle $cycle,
        public DateTimeImmutable $databaseNow,
        public DateTimeImmutable $retryAt,
    ) {
    }

    public function isStarted(): bool
    {
        return null !== $this->cycle;
    }
}
