<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use DateTimeImmutable;
use DateTimeZone;

enum Schedule: string
{
    case CollectorCycle = 'collector_cycle';

    public function scheduledAt(DateTimeImmutable $collectorCycleStartedAt): DateTimeImmutable
    {
        return $collectorCycleStartedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
