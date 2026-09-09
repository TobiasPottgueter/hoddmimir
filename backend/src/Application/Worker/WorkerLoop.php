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
    ): WorkerReadinessReport {
        if ($intervalSeconds < 1) {
            throw new InvalidArgumentException('The worker interval must be at least one second.');
        }

        do {
            $report = $this->readinessProbe->probe($worker);
            if ($report->isReady()) {
                $onIteration($report);
            }

            if (!$once) {
                $this->sleeper->sleep($intervalSeconds);
            }
        } while (!$once);

        return $report;
    }
}
