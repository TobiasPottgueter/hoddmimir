<?php

declare(strict_types=1);

namespace App\Application\Collector;

enum CollectorCycleStatus: string
{
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Abandoned = 'abandoned';
}
