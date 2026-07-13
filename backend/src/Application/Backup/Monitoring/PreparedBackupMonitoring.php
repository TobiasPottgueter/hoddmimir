<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use App\Application\Proxmox\Pve\PveUpid;
use InvalidArgumentException;

final readonly class PreparedBackupMonitoring
{
    public function __construct(
        public PveUpid $upid,
        public int $nextLogOffset,
        public StopAttemptDisposition $stopAttempt,
    ) {
        if ($nextLogOffset < 0) {
            throw new InvalidArgumentException('The next PVE task log offset is invalid.');
        }
    }

    public function cancelWasRequested(): bool
    {
        return StopAttemptDisposition::NotRequested !== $this->stopAttempt;
    }
}
