<?php

declare(strict_types=1);

namespace App\Application\Collector;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CollectorActiveCycle
{
    public function __construct(
        public CollectorLease $lease,
        public DateTimeImmutable $scheduledFor,
        public int $startedMonotonicNanoseconds,
    ) {
        if ($this->startedMonotonicNanoseconds < 0) {
            throw new InvalidArgumentException('A monotonic cycle start must not be negative.');
        }
    }
}
