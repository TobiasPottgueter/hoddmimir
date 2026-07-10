<?php

declare(strict_types=1);

namespace App\Application\Worker;

use App\Domain\Worker\WorkerKind;
use InvalidArgumentException;

final readonly class WorkerLoop
{
    public function __construct(
        private WorkerReadinessProbe $readinessProbe,
        private Sleeper $sleeper,
    ) {
    }

    /**
     * @param callable(WorkerReadinessReport): void $onIteration
     */
    public function run(
        WorkerKind $worker,
        int $intervalSeconds,
        bool $once,
        callable $onIteration,
    ): void {
        if ($intervalSeconds < 1) {
            throw new InvalidArgumentException('The worker interval must be at least one second.');
        }

        while (true) {
            $onIteration($this->readinessProbe->probe($worker));

            if ($once) {
                return;
            }

            $this->sleeper->sleep($intervalSeconds);
        }
    }
}
