<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Collector\CollectorRuntimeWaiter;
use App\Application\Collector\StopRequested;
use InvalidArgumentException;

final readonly class SignalAwareCollectorRuntimeWaiter implements CollectorRuntimeWaiter
{
    private const int MAXIMUM_WAIT_SECONDS = 30;
    private const int POLL_MICROSECONDS = 100_000;

    public function __construct(private StopRequested $stopRequested)
    {
    }

    public function wait(int $seconds): void
    {
        if ($seconds < 1 || $seconds > self::MAXIMUM_WAIT_SECONDS) {
            throw new InvalidArgumentException('The collector runtime wait must be between one and 30 seconds.');
        }

        $deadline = hrtime(true) + ($seconds * 1_000_000_000);
        while (!$this->stopRequested->isStopRequested()) {
            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds <= 0) {
                return;
            }
            usleep((int) min(self::POLL_MICROSECONDS, intdiv($remainingNanoseconds + 999, 1_000)));
        }
    }
}
