<?php

declare(strict_types=1);

namespace App\Application\Collector;

enum CollectorWorkerRunCode: string
{
    case CycleSucceeded = 'cycle_succeeded';
    case CyclePartial = 'cycle_partial';
    case CycleFailed = 'cycle_failed';
    case NoCycleDue = 'no_cycle_due';
    case CollectorStopped = 'collector_stopped';
    case ReadinessUnavailable = 'readiness_unavailable';
    case LeaseOwnershipLost = 'lease_ownership_lost';
    case RuntimeFailed = 'collector_runtime_failed';
}
