<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

final readonly class ConnectionMonitoringResult
{
    public function __construct(
        public MonitoringRunStatus $jobs,
        public MonitoringRunStatus $tasks,
    ) {
    }

    public function isComplete(): bool
    {
        return MonitoringRunStatus::Succeeded === $this->jobs
            && MonitoringRunStatus::Succeeded === $this->tasks;
    }
}
