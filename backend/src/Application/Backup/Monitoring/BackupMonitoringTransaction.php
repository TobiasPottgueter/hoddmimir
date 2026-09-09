<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskStopStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Domain\Backup\MonitoringOutcome;

interface BackupMonitoringTransaction
{
    /** Renews the current fenced claim before another bounded remote I/O operation. */
    public function renew(MonitorClaimedBackupCommand $command): bool;

    /** Loads monitoring state and turns an orphaned dispatching intent into dispatch_unknown. */
    public function prepare(MonitorClaimedBackupCommand $command): ?PreparedBackupMonitoring;

    /** Persists the one allowed dispatching intent after a fresh lease renewal. */
    public function claimStopAttempt(MonitorClaimedBackupCommand $command, PveUpid $upid): bool;

    /** Persists log entries idempotently by their PVE line number. */
    public function appendLogPage(
        MonitorClaimedBackupCommand $command,
        PveUpid $upid,
        PveTaskLogPage $page,
    ): void;

    public function recordStopAttempt(
        MonitorClaimedBackupCommand $command,
        PveUpid $upid,
        ?PveTaskStopStatus $status,
        ?PveBackupApiFailureCode $failure,
    ): void;

    /** Applies the fenced observation and performs terminal resource release atomically. */
    public function recordObservation(
        MonitorClaimedBackupCommand $command,
        PveUpid $upid,
        MonitoringOutcome $outcome,
        ?string $exitStatus,
        ?PveBackupApiFailureCode $failure,
    ): void;
}
