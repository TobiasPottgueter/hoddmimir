<?php

declare(strict_types=1);

namespace App\Application\Collector;

/**
 * The infrastructure implementation must use a monotonic clock and return
 * promptly when the process signal state changes.
 */
interface CollectorRuntimeWaiter
{
    public function wait(int $seconds): void;
}
