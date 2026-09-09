<?php

declare(strict_types=1);

namespace App\Application\Collector;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class GridSchedule
{
    public const int DEFAULT_WIDTH_SECONDS = 120;
    public const int MAXIMUM_WIDTH_SECONDS = 31_536_000;

    private DateTimeImmutable $startedAt;

    public function __construct(
        DateTimeImmutable $startedAt,
        public int $widthSeconds = self::DEFAULT_WIDTH_SECONDS,
    ) {
        if ($this->widthSeconds < 1 || $this->widthSeconds > self::MAXIMUM_WIDTH_SECONDS) {
            throw new InvalidArgumentException('The collector grid width must be between one second and one year.');
        }

        $this->startedAt = $startedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function nextTickAfter(DateTimeImmutable $instant): DateTimeImmutable
    {
        $instantMicroseconds = self::epochMicroseconds($instant);
        $startedAtMicroseconds = self::epochMicroseconds($this->startedAt);

        if ($instantMicroseconds < $startedAtMicroseconds) {
            throw new InvalidArgumentException('The collector grid cannot schedule before its start time.');
        }

        $widthMicroseconds = $this->widthSeconds * 1_000_000;
        $tick = intdiv($instantMicroseconds - $startedAtMicroseconds, $widthMicroseconds) + 1;

        return self::fromEpochMicroseconds($startedAtMicroseconds + ($tick * $widthMicroseconds));
    }

    private static function epochMicroseconds(DateTimeImmutable $instant): int
    {
        return ((int) $instant->format('U') * 1_000_000) + (int) $instant->format('u');
    }

    private static function fromEpochMicroseconds(int $microseconds): DateTimeImmutable
    {
        $seconds = intdiv($microseconds, 1_000_000);
        $remainder = $microseconds % 1_000_000;
        $instant = (new DateTimeImmutable(sprintf('@%d', $seconds)))
            ->modify(sprintf('+%d microseconds', $remainder));

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
