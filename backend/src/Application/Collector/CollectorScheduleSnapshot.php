<?php

declare(strict_types=1);

namespace App\Application\Collector;

use DateTimeImmutable;

final readonly class CollectorScheduleSnapshot
{
    public function __construct(
        public GridSchedule $grid,
        public DateTimeImmutable $nextScanAt,
        public DateTimeImmutable $databaseNow,
    ) {
    }
}
