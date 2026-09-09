<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

enum MonitoringRunStatus: string
{
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
