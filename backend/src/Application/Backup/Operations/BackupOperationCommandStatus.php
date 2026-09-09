<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

enum BackupOperationCommandStatus: string
{
    case Applied = 'applied';
    case Replayed = 'replayed';
    case Conflict = 'conflict';
    case Blocked = 'blocked';
}
