<?php

declare(strict_types=1);

namespace App\Application\Backup\Worker;

use App\Application\Backup\Execution\BackupExecutionGate;
use App\Application\Backup\Notification\BackupNotificationConfiguration;
use App\Application\Backup\Notification\BackupNotificationDeliveryGate;

final readonly class BackupWorkerRuntimeSafety
{
    public function __construct(
        private BackupExecutionGate $execution,
        private BackupNotificationDeliveryGate $delivery,
        private BackupNotificationConfiguration $configuration,
    ) {
    }

    public function allowsStartup(): bool
    {
        return !$this->execution->enabled()
            || ($this->delivery->enabled() && $this->configuration->isValid());
    }
}
