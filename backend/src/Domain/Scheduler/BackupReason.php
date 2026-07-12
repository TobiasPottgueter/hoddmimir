<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

enum BackupReason: string
{
    case Manual = 'manual';
    case NeverBackedUp = 'never_backed_up';
    case MaxAge = 'max_age';
    case BytesWritten = 'bytes_written';
}
