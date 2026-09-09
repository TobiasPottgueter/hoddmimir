<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveTaskStopStatus;
use InvalidArgumentException;

final readonly class MonitoringTickResult
{
    public function __construct(
        public MonitoringTickStatus $status,
        public int $logEntries,
        public ?PveBackupApiFailureCode $logFailure = null,
        public ?PveBackupApiFailureCode $statusFailure = null,
        public ?PveTaskStopStatus $stopStatus = null,
        public ?PveBackupApiFailureCode $stopFailure = null,
    ) {
        if ($logEntries < 0
            || (null !== $stopStatus && null !== $stopFailure)
            || (MonitoringTickStatus::NoWork === $status
                && (0 !== $logEntries || null !== $logFailure || null !== $statusFailure
                    || null !== $stopStatus || null !== $stopFailure))
            || (null !== $statusFailure && MonitoringTickStatus::TemporarilyUnavailable !== $status)) {
            throw new InvalidArgumentException('The monitoring tick result is inconsistent.');
        }
    }
}
