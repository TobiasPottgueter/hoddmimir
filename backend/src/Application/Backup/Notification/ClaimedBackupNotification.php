<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

use InvalidArgumentException;

final readonly class ClaimedBackupNotification
{
    public function __construct(
        public BackupNotification $notification,
        public string $claimToken,
        public int $deliveryAttempt,
    ) {
        if (16 !== strlen($claimToken) || $deliveryAttempt < 1) {
            throw new InvalidArgumentException('The claimed backup notification is invalid.');
        }
    }
}
