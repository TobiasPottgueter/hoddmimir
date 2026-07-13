<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PolicyThresholds
{
    public ?UInt64Decimal $bytesWritten;

    public function __construct(
        public ?int $maximumAgeSeconds,
        ?string $bytesWritten,
        public ?int $cooldownSeconds,
    ) {
        if (null !== $maximumAgeSeconds && $maximumAgeSeconds <= 0) {
            throw new InvalidArgumentException('The maximum-age threshold must be positive.');
        }
        if ((null === $bytesWritten) !== (null === $cooldownSeconds)) {
            throw new InvalidArgumentException('The byte threshold and cooldown must be configured together.');
        }
        if (null !== $cooldownSeconds && $cooldownSeconds < 0) {
            throw new InvalidArgumentException('The byte cooldown must not be negative.');
        }
        $this->bytesWritten = null === $bytesWritten ? null : new UInt64Decimal($bytesWritten);
        if (null !== $this->bytesWritten && '0' === $this->bytesWritten->value) {
            throw new InvalidArgumentException('The byte threshold must be positive.');
        }
        if (null === $maximumAgeSeconds && null === $this->bytesWritten) {
            throw new InvalidArgumentException('At least one policy trigger must be configured.');
        }
    }

    public function maximumAgeExceeded(DateTimeImmutable $now, ?DateTimeImmutable $lastSuccessAt): bool
    {
        if (null === $this->maximumAgeSeconds || null === $lastSuccessAt) {
            return false;
        }

        return self::utc($now) > self::boundary($lastSuccessAt, $this->maximumAgeSeconds);
    }

    public function bytesWrittenExceeded(
        DateTimeImmutable $now,
        ?DateTimeImmutable $lastSuccessfulBackupAt,
        UInt64Decimal $currentBytes,
        UInt64Decimal $baselineBytes,
    ): bool {
        if (null === $lastSuccessfulBackupAt
            || null === $this->bytesWritten
            || null === $this->cooldownSeconds
            || self::utc($now) <= self::boundary($lastSuccessfulBackupAt, $this->cooldownSeconds)
            || $currentBytes->lessThanOrEqual($baselineBytes)) {
            return false;
        }

        $difference = self::subtract($currentBytes->value, $baselineBytes->value);

        return self::compare($difference, $this->bytesWritten->value) > 0;
    }

    /** @return array{maximumAgeSeconds: ?int, bytesWritten: ?string, cooldownSeconds: ?int} */
    public function snapshot(): array
    {
        return [
            'maximumAgeSeconds' => $this->maximumAgeSeconds,
            'bytesWritten' => $this->bytesWritten?->value,
            'cooldownSeconds' => $this->cooldownSeconds,
        ];
    }

    private static function boundary(DateTimeImmutable $anchor, int $seconds): DateTimeImmutable
    {
        return self::utc($anchor)->modify('+'.$seconds.' seconds');
    }

    private static function utc(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    private static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function subtract(string $minuend, string $subtrahend): string
    {
        $result = '';
        $borrow = 0;
        $subtrahend = str_pad($subtrahend, strlen($minuend), '0', STR_PAD_LEFT);
        for ($index = strlen($minuend) - 1; $index >= 0; --$index) {
            $digit = ((int) $minuend[$index]) - ((int) $subtrahend[$index]) - $borrow;
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) $digit.$result;
        }

        return ltrim($result, '0') ?: '0';
    }
}
