<?php

declare(strict_types=1);

namespace App\Domain\Backup;

enum SubmissionProvenance: string
{
    case NotSubmitted = 'not_submitted';
    case Accepted = 'accepted';
    case DefinitiveRejection = 'definitive_rejection';
    case Ambiguous = 'ambiguous';
}
