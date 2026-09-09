<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

enum MonitoringSourceKind: string
{
    case Jobs = 'jobs';
    case Active = 'active';
    case Archive = 'archive';
    case Running = 'running';
    case History = 'history';
}
