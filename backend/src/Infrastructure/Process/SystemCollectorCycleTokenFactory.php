<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorCycleTokenFactory;
use RuntimeException;
use Throwable;

final readonly class SystemCollectorCycleTokenFactory implements CollectorCycleTokenFactory
{
    public function generate(): CollectorCycleToken
    {
        try {
            return new CollectorCycleToken(random_bytes(16));
        } catch (Throwable) {
            throw new RuntimeException('The collector cycle token could not be generated.');
        }
    }
}
