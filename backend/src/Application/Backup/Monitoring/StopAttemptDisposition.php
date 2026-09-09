<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

enum StopAttemptDisposition: string
{
    case NotRequested = 'not_requested';
    case ReadyToClaim = 'ready_to_claim';
    case DispatchUnknown = 'dispatch_unknown';
    case AlreadyAttempted = 'already_attempted';
}
