<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

interface BackupNotificationConfiguration
{
    public function isValid(): bool;
}
