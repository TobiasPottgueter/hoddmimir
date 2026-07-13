<?php

declare(strict_types=1);

namespace App\Domain\Backup;

enum SubmissionOutcomeKind: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Ambiguous = 'ambiguous';
}
