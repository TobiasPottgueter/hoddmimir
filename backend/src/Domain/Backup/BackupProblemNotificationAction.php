<?php

declare(strict_types=1);

namespace App\Domain\Backup;

enum BackupProblemNotificationAction: string
{
    case None = 'none';
    case Opened = 'opened';
    case Changed = 'changed';
    case RepeatedFailure = 'repeated_failure';
    case Resolved = 'resolved';
}
