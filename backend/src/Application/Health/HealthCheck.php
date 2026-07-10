<?php

declare(strict_types=1);

namespace App\Application\Health;

use App\Domain\Shared\Clock;

final readonly class HealthCheck
{
    public function __construct(private Clock $clock)
    {
    }

    public function check(): HealthReport
    {
        return new HealthReport($this->clock->now());
    }
}
