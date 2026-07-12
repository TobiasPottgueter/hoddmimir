<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Process;

use App\Application\Collector\StopRequested;
use App\Infrastructure\Process\SignalAwareCollectorRuntimeWaiter;
use PHPUnit\Framework\TestCase;

final class SignalAwareCollectorRuntimeWaiterTest extends TestCase
{
    public function testRequestedStopReturnsWellBeforeTheOneSecondDeadline(): void
    {
        $stop = new class implements StopRequested {
            public int $calls = 0;

            public function isStopRequested(): bool
            {
                ++$this->calls;

                return true;
            }
        };
        $started = hrtime(true);

        (new SignalAwareCollectorRuntimeWaiter($stop))->wait(1);

        self::assertLessThan(100_000_000, hrtime(true) - $started);
        self::assertSame(1, $stop->calls);
    }
}
