<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

enum SubmissionPreparationStatus: string
{
    case PreparedNow = 'prepared_now';
    case Blocked = 'blocked';
    case RecoveryRequired = 'recovery_required';
}
