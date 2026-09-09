<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Collector\StopRequested;
use RuntimeException;

final class PcntlStopRequested implements StopRequested
{
    private bool $requested = false;

    public function __construct()
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            throw new RuntimeException('The collector requires the pcntl extension.');
        }

        pcntl_async_signals(true);
        $handler = function (): void {
            $this->requested = true;
        };
        if (!pcntl_signal(SIGTERM, $handler) || !pcntl_signal(SIGINT, $handler)) {
            throw new RuntimeException('The collector signal handlers could not be installed.');
        }
    }

    public function isStopRequested(): bool
    {
        pcntl_signal_dispatch();

        return $this->requested;
    }
}
