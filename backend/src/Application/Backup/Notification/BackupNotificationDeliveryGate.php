<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

interface BackupNotificationDeliveryGate
{
    public function enabled(): bool;
}
