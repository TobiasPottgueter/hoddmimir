<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Backup\Notification\BackupNotificationDeliveryGate;

final readonly class ConfiguredBackupNotificationDeliveryGate implements BackupNotificationDeliveryGate
{
    public function __construct(private bool $value,
        private \App\Application\Maintenance\MaintenanceAccess $maintenance = new \App\Application\Maintenance\UnrestrictedMaintenanceAccess(),
    )
    {
    }

    public function enabled(): bool
    {
        return $this->value && \App\Application\Maintenance\MaintenancePhase::Open === $this->maintenance->phase();
    }
}
