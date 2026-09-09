<?php

declare(strict_types=1);

namespace App\Domain\Backup;

enum RecoveryOutcomeKind: string
{
    case Matched = 'matched';
    case ProvenNotStarted = 'proven_not_started';
    case MultipleMatches = 'multiple_matches';
    case Inconclusive = 'inconclusive';
}
