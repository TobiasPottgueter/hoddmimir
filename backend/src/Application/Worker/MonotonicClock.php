<?php

declare(strict_types=1);

namespace App\Application\Worker;

interface MonotonicClock
{
    public function nowNanoseconds(): int;
}
