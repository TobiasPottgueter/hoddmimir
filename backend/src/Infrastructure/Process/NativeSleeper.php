<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Worker\Sleeper;

final readonly class NativeSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
