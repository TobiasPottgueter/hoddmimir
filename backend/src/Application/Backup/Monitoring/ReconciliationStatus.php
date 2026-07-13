<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

enum ReconciliationStatus: string
{
    case NoWork = 'no_work';
    case Matched = 'matched';
    case ProvenNotStarted = 'proven_not_started';
    case Inconclusive = 'inconclusive';
    case TemporarilyUnavailable = 'temporarily_unavailable';
}
