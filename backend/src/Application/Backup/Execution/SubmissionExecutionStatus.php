<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

enum SubmissionExecutionStatus: string
{
    case Disabled = 'disabled';
    case Blocked = 'blocked';
    case RecoveryRequired = 'recovery_required';
    case Accepted = 'accepted';
    case DefinitiveRejection = 'definitive_rejection';
    case Ambiguous = 'ambiguous';
}
