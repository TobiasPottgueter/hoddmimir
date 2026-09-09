<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Backup\Worker\BackupNotificationDeliveryHook;
use DateTimeImmutable;

final readonly class NoOpBackupNotificationDeliveryHook implements BackupNotificationDeliveryHook
{
    public function deliverOne(DateTimeImmutable $now): void {}
}
