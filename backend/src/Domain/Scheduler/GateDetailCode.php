<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

enum GateDetailCode: string
{
    case Passed = 'passed';
    case Disabled = 'disabled';
    case ExplicitlyExcluded = 'explicitly_excluded';
    case Archived = 'archived';
    case Missing = 'missing';
    case Stale = 'stale';
    case NotAllowed = 'not_allowed';
    case Inactive = 'inactive';
    case Unauthorized = 'unauthorized';
    case InsufficientFreeSpace = 'insufficient_free_space';
    case ConcurrencyLimitReached = 'concurrency_limit_reached';
    case InvalidMapping = 'invalid_mapping';
    case Incompatible = 'incompatible';
    case ActiveRequestExists = 'active_request_exists';
    case HigherRankedCandidate = 'higher_ranked_candidate';
}
