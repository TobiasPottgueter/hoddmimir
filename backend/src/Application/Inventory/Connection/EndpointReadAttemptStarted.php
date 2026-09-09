<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class EndpointReadAttemptStarted
{
    public DateTimeImmutable $startedAt;

    public function __construct(
        public EndpointId $endpointId,
        public int $attemptNumber,
        DateTimeImmutable $startedAt,
    ) {
        if ($this->attemptNumber < 1) {
            throw new InvalidArgumentException('An endpoint read attempt number must be positive.');
        }
        $this->startedAt = $startedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
