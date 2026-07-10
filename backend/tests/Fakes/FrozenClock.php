<?php

declare(strict_types=1);

namespace App\Tests\Fakes;

use App\Domain\Shared\Clock;
use DateTimeImmutable;

final readonly class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
