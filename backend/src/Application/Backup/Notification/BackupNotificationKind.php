<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

enum BackupNotificationKind: string
{
    case Failure = 'failure';
    case AttentionRequired = 'attention_required';
    case Recovery = 'recovery';
}
