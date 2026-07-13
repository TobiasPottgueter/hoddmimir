<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

enum BackupOperationCommandType: string
{
    case ManualRequest = 'manual_request';
    case CancelRequest = 'cancel_request';
}
