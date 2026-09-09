<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ByteReasonEvidence
{
    private function __construct(
        public DateTimeImmutable $cooldownBoundary,
        public string $currentBytes,
        public string $baselineBytes,
        public string $bytesThreshold,
    ) {
    }

    public static function fromCounters(
        DateTimeImmutable $cooldownBoundary,
        int|string $currentBytes,
        int|string $baselineBytes,
        int|string $bytesThreshold,
    ): ?self {
        if ((\is_int($currentBytes) && $currentBytes < 0) || (\is_int($baselineBytes) && $baselineBytes < 0)
            || (\is_int($bytesThreshold) && $bytesThreshold < 0)) {
            throw new InvalidArgumentException('Byte counters and thresholds must not be negative.');
        }
        $current = new UInt64Decimal((string) $currentBytes);
        $baseline = new UInt64Decimal((string) $baselineBytes);
        $threshold = new UInt64Decimal((string) $bytesThreshold);
        if (!$baseline->lessThanOrEqual($current)) {
            return null;
        }

        return new self(
            $cooldownBoundary->setTimezone(new DateTimeZone('UTC')),
            $current->value,
            $baseline->value,
            $threshold->value,
        );
    }

    public function thresholdExceeded(): bool
    {
        $difference = self::subtract($this->currentBytes, $this->baselineBytes);
        return !(new UInt64Decimal($difference))->lessThanOrEqual(new UInt64Decimal($this->bytesThreshold));
    }

    private static function subtract(string $minuend, string $subtrahend): string
    {
        $result = ''; $borrow = 0; $subtrahend = \str_pad($subtrahend, \strlen($minuend), '0', STR_PAD_LEFT);
        for ($index = \strlen($minuend) - 1; $index >= 0; --$index) {
            $digit = (int) $minuend[$index] - (int) $subtrahend[$index] - $borrow;
            if ($digit < 0) { $digit += 10; $borrow = 1; } else { $borrow = 0; }
            $result = (string) $digit.$result;
        }
        return \ltrim($result, '0') ?: '0';
    }
}
