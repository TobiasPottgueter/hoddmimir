<?php

declare(strict_types=1);

namespace App\Infrastructure\Time;

use App\Application\Worker\MonotonicClock;

final readonly class SystemMonotonicClock implements MonotonicClock
{
    public function nowNanoseconds(): int
    {
        return hrtime(true);
    }
}
