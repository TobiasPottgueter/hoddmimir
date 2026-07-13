<?php

declare(strict_types=1);

namespace App\Application\Backup\Worker;

use DateTimeImmutable;

interface BackupWorkerHeartbeatStore
{
    public function record(
        string $workerId,
        BackupWorkerHeartbeatStatus $status,
        string $activity,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $nextActionAt,
    ): void;
}
