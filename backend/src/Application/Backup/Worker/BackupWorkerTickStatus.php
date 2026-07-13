<?php

declare(strict_types=1);

namespace App\Application\Backup\Worker;

enum BackupWorkerTickStatus: string
{
    case NoWork = 'no_work';
    case Submitted = 'submitted';
    case SubmissionDeferred = 'submission_deferred';
    case Monitored = 'monitored';
    case Reconciled = 'reconciled';
}
