<?php

declare(strict_types=1);

namespace App\Application\Backup\Worker;

interface BackupWorkerRuntime
{
    public function runOnce(string $workerId): BackupWorkerTickStatus;
}
