<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Collector;

use App\Application\Collector\GridSchedule;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GridScheduleTest extends TestCase
{
    public function testItDefaultsToTheTwoMinuteGridAndNormalizesTheStartToUtc(): void
    {
        $schedule = new GridSchedule(new DateTimeImmutable('2026-07-10T14:00:00.123456+02:00'));

        self::assertSame(120, $schedule->widthSeconds);
        self::assertSame('2026-07-10T12:00:00.123456+00:00', $schedule->startedAt()->format('Y-m-d\TH:i:s.uP'));
    }

    /** @return iterable<string, array{string, int, string, string}> */
    public static function futureTicks(): iterable
    {
        yield 'completion before first tick' => [
            '2026-07-10T12:00:00.123456+00:00',
            120,
            '2026-07-10T12:01:59.999999+00:00',
            '2026-07-10T12:02:00.123456+00:00',
        ];
        yield 'completion exactly on a tick skips to the next future tick' => [
            '2026-07-10T12:00:00.000000+00:00',
            120,
            '2026-07-10T12:02:00.000000+00:00',
            '2026-07-10T12:04:00.000000+00:00',
        ];
        yield 'overrun skips every missed tick without catch-up' => [
            '2026-07-10T12:00:00.000000+00:00',
            120,
            '2026-07-10T12:06:17.999999+00:00',
            '2026-07-10T12:08:00.000000+00:00',
        ];
        yield 'deployment override changes only the grid width' => [
            '2026-07-10T12:00:00.000000+00:00',
            17,
            '2026-07-10T12:00:52.000000+00:00',
            '2026-07-10T12:01:08.000000+00:00',
        ];
    }

    #[DataProvider('futureTicks')]
    public function testItReturnsOnlyTheNextFutureGridTick(
        string $startedAt,
        int $widthSeconds,
        string $completedAt,
        string $expected,
    ): void {
        $schedule = new GridSchedule(new DateTimeImmutable($startedAt), $widthSeconds);

        self::assertSame(
            $expected,
            $schedule->nextTickAfter(new DateTimeImmutable($completedAt))->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function testItRejectsANonPositiveGridWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('grid width');

        new GridSchedule(new DateTimeImmutable('2026-07-10T12:00:00+00:00'), 0);
    }

    public function testItRejectsAWidthAboveOneYearBeforeArithmeticCanOverflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('one year');

        new GridSchedule(
            new DateTimeImmutable('2026-07-10T12:00:00+00:00'),
            GridSchedule::MAXIMUM_WIDTH_SECONDS + 1,
        );
    }

    public function testItRejectsSchedulingBeforeTheGridStart(): void
    {
        $schedule = new GridSchedule(new DateTimeImmutable('2026-07-10T12:00:00+00:00'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('before its start time');

        $schedule->nextTickAfter(new DateTimeImmutable('2026-07-10T11:59:59.999999+00:00'));
    }
}
