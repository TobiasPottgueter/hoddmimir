<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

enum ExecutorEvidenceRefreshStatus: string
{
    case NoDueConnection = 'no_due_connection';
    case Published = 'published';
    case Failed = 'failed';
}
