<?php

declare(strict_types=1);

namespace App\Application\Collector;

enum CollectorWorkerStatus: string
{
    case Starting = 'starting';
    case Ready = 'ready';
    case Busy = 'busy';
    case Degraded = 'degraded';
    case Stopping = 'stopping';
}
