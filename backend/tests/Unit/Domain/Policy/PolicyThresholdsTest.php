<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Policy;

use App\Domain\Policy\PolicyThresholds;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyThresholdsTest extends TestCase
{
    #[DataProvider('invalidThresholdProvider')]
    public function testIncompleteOrInvalidTriggerConfigurationFailsClosed(
        ?int $maximumAge,
        ?string $bytes,
        ?int $cooldown,
    ): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyThresholds($maximumAge, $bytes, $cooldown);
    }

    /** @return iterable<string, array{?int, ?string, ?int}> */
    public static function invalidThresholdProvider(): iterable
    {
        yield 'maximum age zero' => [0, null, null];
        yield 'maximum age negative' => [-1, null, null];
        yield 'bytes without cooldown' => [null, '1', null];
        yield 'cooldown without bytes' => [null, null, 0];
        yield 'negative cooldown' => [null, '1', -1];
        yield 'byte threshold zero' => [null, '0', 0];
        yield 'no trigger' => [null, null, null];
    }

    public function testByteThresholdMustBeCanonicalUInt64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyThresholds(null, '01', 1);
    }

    public function testMaximumAgeIsStrictAtTheMicrosecondBoundary(): void
    {
        $thresholds = new PolicyThresholds(60, null, null);
        $lastSuccess = new DateTimeImmutable('2026-07-12T22:00:00.000000+02:00');

        self::assertFalse($thresholds->maximumAgeExceeded(
            new DateTimeImmutable('2026-07-12T20:01:00.000000Z'),
            null,
        ));
        self::assertFalse($thresholds->maximumAgeExceeded(
            new DateTimeImmutable('2026-07-12T20:01:00.000000Z'),
            $lastSuccess,
        ));
        self::assertTrue($thresholds->maximumAgeExceeded(
            new DateTimeImmutable('2026-07-12T20:01:00.000001Z'),
            $lastSuccess,
        ));
        self::assertFalse($thresholds->bytesWrittenExceeded(
            new DateTimeImmutable('2026-07-12T20:01:00.000001Z'),
            $lastSuccess,
            new UInt64Decimal('2'),
            new UInt64Decimal('0'),
        ));
    }

    public function testByteAndCooldownThresholdsAreBothStrict(): void
    {
        $thresholds = new PolicyThresholds(null, '100', 30);
        $anchor = new DateTimeImmutable('2026-07-12T20:00:00.000000Z');
        $atCooldown = new DateTimeImmutable('2026-07-12T20:00:30.000000Z');
        $afterCooldown = new DateTimeImmutable('2026-07-12T20:00:30.000001Z');

        self::assertFalse($thresholds->bytesWrittenExceeded(
            $afterCooldown,
            null,
            new UInt64Decimal('201'),
            new UInt64Decimal('100'),
        ));
        self::assertFalse($thresholds->bytesWrittenExceeded(
            $atCooldown,
            $anchor,
            new UInt64Decimal('201'),
            new UInt64Decimal('100'),
        ));
        self::assertFalse($thresholds->bytesWrittenExceeded(
            $afterCooldown,
            $anchor,
            new UInt64Decimal('100'),
            new UInt64Decimal('100'),
        ));
        self::assertFalse($thresholds->bytesWrittenExceeded(
            $afterCooldown,
            $anchor,
            new UInt64Decimal('99'),
            new UInt64Decimal('100'),
        ));
        self::assertFalse($thresholds->bytesWrittenExceeded(
            $afterCooldown,
            $anchor,
            new UInt64Decimal('200'),
            new UInt64Decimal('100'),
        ));
        self::assertTrue($thresholds->bytesWrittenExceeded(
            $afterCooldown,
            $anchor,
            new UInt64Decimal('201'),
            new UInt64Decimal('100'),
        ));
    }

    public function testUInt64DifferenceDoesNotRoundOrOverflow(): void
    {
        $thresholds = new PolicyThresholds(1, '1', 0);
        $now = new DateTimeImmutable('2026-07-12T20:00:00.000001Z');
        $anchor = new DateTimeImmutable('2026-07-12T20:00:00.000000Z');

        self::assertFalse($thresholds->bytesWrittenExceeded(
            $now,
            $anchor,
            new UInt64Decimal('1000'),
            new UInt64Decimal('999'),
        ));
        self::assertTrue($thresholds->bytesWrittenExceeded(
            $now,
            $anchor,
            new UInt64Decimal(UInt64Decimal::MAXIMUM),
            new UInt64Decimal('0'),
        ));
        self::assertSame([
            'maximumAgeSeconds' => 1,
            'bytesWritten' => '1',
            'cooldownSeconds' => 0,
        ], $thresholds->snapshot());
    }

    public function testAgeOnlyAndBytesOnlySnapshotsKeepUnconfiguredTriggersNull(): void
    {
        self::assertSame([
            'maximumAgeSeconds' => 60,
            'bytesWritten' => null,
            'cooldownSeconds' => null,
        ], (new PolicyThresholds(60, null, null))->snapshot());
        self::assertSame([
            'maximumAgeSeconds' => null,
            'bytesWritten' => '1',
            'cooldownSeconds' => 0,
        ], (new PolicyThresholds(null, '1', 0))->snapshot());
    }
}
