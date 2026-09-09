<?php

declare(strict_types=1);

namespace App\Domain\Backup;

enum MonitoringOutcome: string
{
    case Running = 'running';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
