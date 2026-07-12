<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ByteReasonEvidence
{
    private function __construct(
        public DateTimeImmutable $cooldownBoundary,
        public int $currentBytes,
        public int $baselineBytes,
        public int $bytesThreshold,
    ) {
    }

    public static function fromCounters(
        DateTimeImmutable $cooldownBoundary,
        int $currentBytes,
        int $baselineBytes,
        int $bytesThreshold,
    ): ?self {
        if ($currentBytes < 0 || $baselineBytes < 0 || $bytesThreshold < 0) {
            throw new InvalidArgumentException('Byte counters and thresholds must not be negative.');
        }
        if ($currentBytes < $baselineBytes) {
            return null;
        }

        return new self(
            $cooldownBoundary->setTimezone(new DateTimeZone('UTC')),
            $currentBytes,
            $baselineBytes,
            $bytesThreshold,
        );
    }
}
