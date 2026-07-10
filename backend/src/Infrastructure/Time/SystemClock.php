<?php

declare(strict_types=1);

namespace App\Infrastructure\Time;

use App\Domain\Shared\Clock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
