<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

enum BackupRunState: string
{
    case AwaitingSubmission = 'awaiting_submission';
    case ReconcileRequired = 'reconcile_required';
    case Running = 'running';
    case CancelRequested = 'cancel_requested';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
