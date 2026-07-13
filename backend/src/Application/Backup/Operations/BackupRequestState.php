<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

enum BackupRequestState: string
{
    case Pending = 'pending';
    case RetryWait = 'retry_wait';
    case Leased = 'leased';
    case Starting = 'starting';
    case Running = 'running';
    case ReconcileRequired = 'reconcile_required';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function terminal(): bool
    {
        return \in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Unknown], true);
    }
}
