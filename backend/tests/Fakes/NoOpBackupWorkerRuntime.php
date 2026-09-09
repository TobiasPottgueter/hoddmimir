<?php

declare(strict_types=1);

namespace App\Tests\Fakes;

use App\Application\Backup\Worker\BackupWorkerRuntime;
use App\Application\Backup\Worker\BackupWorkerTickStatus;

final class NoOpBackupWorkerRuntime implements BackupWorkerRuntime
{
    public function runOnce(string $workerId): BackupWorkerTickStatus
    {
        return BackupWorkerTickStatus::NoWork;
    }
}
