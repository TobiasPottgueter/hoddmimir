<?php

declare(strict_types=1);

namespace App\Application\Backup\Worker;

use DateTimeImmutable;

interface BackupNotificationDeliveryHook
{
    public function deliverOne(DateTimeImmutable $now): void;
}
