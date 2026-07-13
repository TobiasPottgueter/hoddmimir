<?php

declare(strict_types=1);

namespace App\Domain\Backup;

enum BackupRequestState: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case Starting = 'starting';
    case Running = 'running';
    case RetryWait = 'retry_wait';
    case ReconcileRequired = 'reconcile_required';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return isset([
            'succeeded' => true,
            'failed' => true,
            'cancelled' => true,
            'unknown' => true,
        ][$this->value]);
    }
}
