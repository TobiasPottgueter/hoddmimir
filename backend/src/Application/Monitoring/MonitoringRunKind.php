<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

enum MonitoringRunKind: string
{
    case ExternalJobs = 'external_jobs';
    case ObservedTasks = 'observed_tasks';
}
