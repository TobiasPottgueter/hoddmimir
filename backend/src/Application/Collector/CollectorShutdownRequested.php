<?php

declare(strict_types=1);

namespace App\Application\Collector;

use RuntimeException;

final class CollectorShutdownRequested extends RuntimeException
{
    public function __construct(public readonly CollectorActiveCycle $cycle)
    {
        parent::__construct('Collector shutdown was requested at a safe checkpoint.');
    }
}
