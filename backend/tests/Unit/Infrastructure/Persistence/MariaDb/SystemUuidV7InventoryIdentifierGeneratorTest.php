<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Infrastructure\Persistence\MariaDb\SystemUuidV7InventoryIdentifierGenerator;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SystemUuidV7InventoryIdentifierGeneratorTest extends TestCase
{
    public function testItGeneratesDistinctRfc9562UuidV7BinaryIdentifiersFromUtcMilliseconds(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-11T12:34:56.789123+00:00'));
        $generator = new SystemUuidV7InventoryIdentifierGenerator($clock);
        $first = $generator->generate()->binary();
        $second = $generator->generate()->binary();

        self::assertNotSame($first, $second);
        self::assertSame(16, strlen($first));
        self::assertSame(7, ord($first[6]) >> 4);
        self::assertSame(2, ord($first[8]) >> 6);
        $timestamp = unpack('Nhigh/nlow', substr($first, 0, 6));
        self::assertIsArray($timestamp);
        $high = $timestamp['high'] ?? null;
        $low = $timestamp['low'] ?? null;
        self::assertIsInt($high);
        self::assertIsInt($low);
        self::assertSame(
            ((int) $clock->now()->format('U') * 1000) + 789,
            ($high * 65_536) + $low,
        );
    }

    public function testItRejectsInstantsOutsideTheUuidV7Range(): void
    {
        $generator = new SystemUuidV7InventoryIdentifierGenerator(
            new FrozenClock(new DateTimeImmutable('1969-12-31T23:59:59.999000+00:00')),
        );
        $this->expectException(RuntimeException::class);
        $generator->generate();
    }
}
