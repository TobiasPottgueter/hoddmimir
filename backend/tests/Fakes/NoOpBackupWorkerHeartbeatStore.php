<?php

declare(strict_types=1);

namespace App\Tests\Fakes;

use App\Application\Backup\Worker\BackupWorkerHeartbeatStatus;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStore;
use DateTimeImmutable;

final class NoOpBackupWorkerHeartbeatStore implements BackupWorkerHeartbeatStore
{
    public function record(string $workerId, BackupWorkerHeartbeatStatus $status, string $activity, DateTimeImmutable $observedAt, DateTimeImmutable $nextActionAt): void {}
}
