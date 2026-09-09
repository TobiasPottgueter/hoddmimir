<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

enum MonitoringTickStatus: string
{
    case NoWork = 'no_work';
    case Running = 'running';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
