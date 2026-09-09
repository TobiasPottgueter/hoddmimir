<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class QueueOrderEntry
{
    public DateTimeImmutable $scheduledAt;

    public function __construct(
        public string $stableId,
        public Priority $priority,
        DateTimeImmutable $scheduledAt,
    ) {
        if (16 !== strlen($stableId)) {
            throw new InvalidArgumentException('A queue ordering ID must contain exactly 16 bytes.');
        }

        $this->scheduledAt = $scheduledAt->setTimezone(new DateTimeZone('UTC'));
    }
}
