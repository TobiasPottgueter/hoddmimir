<?php

declare(strict_types=1);

namespace App\Application\Collector;

interface StopRequested
{
    /** @phpstan-impure */
    public function isStopRequested(): bool;
}
