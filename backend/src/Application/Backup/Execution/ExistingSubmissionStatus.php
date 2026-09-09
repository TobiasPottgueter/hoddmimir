<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

enum ExistingSubmissionStatus: string
{
    case FreshClaim = 'fresh_claim';
    case RecoveryRequired = 'recovery_required';
}
