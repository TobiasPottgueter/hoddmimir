<?php

declare(strict_types=1);

namespace App\Application\Backup\Worker;

enum BackupWorkerHeartbeatStatus: string
{
    case Starting = 'starting';
    case Ready = 'ready';
    case Busy = 'busy';
    case Degraded = 'degraded';
    case Stopping = 'stopping';
}
