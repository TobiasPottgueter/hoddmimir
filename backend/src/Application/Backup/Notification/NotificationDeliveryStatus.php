<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

enum NotificationDeliveryStatus: string
{
    case NoWork = 'no_work';
    case Sent = 'sent';
    case Rescheduled = 'rescheduled';
}
