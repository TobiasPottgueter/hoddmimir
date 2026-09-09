<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

use DateTimeImmutable;

interface BackupNotificationDeliveryStore
{
    public function claim(DateTimeImmutable $now): ?ClaimedBackupNotification;

    public function markSent(ClaimedBackupNotification $claimed, DateTimeImmutable $sentAt): void;

    public function reschedule(
        ClaimedBackupNotification $claimed,
        MatrixWebhookFailureCode $failure,
        DateTimeImmutable $failedAt,
        DateTimeImmutable $nextAttemptAt,
    ): void;
}
