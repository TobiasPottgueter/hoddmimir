<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Backup\Notification\DeliverBackupNotification;
use App\Application\Backup\Worker\BackupNotificationDeliveryHook;
use DateTimeImmutable;

final readonly class ApplicationBackupNotificationDeliveryHook implements BackupNotificationDeliveryHook
{
    public function __construct(private DeliverBackupNotification $delivery) {}

    public function deliverOne(DateTimeImmutable $now): void
    {
        $this->delivery->execute($now);
    }
}
