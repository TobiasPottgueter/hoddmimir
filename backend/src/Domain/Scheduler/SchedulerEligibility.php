<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

final readonly class SchedulerEligibility
{
    public function __construct(
        public DecisionOutcome $outcome,
        public ?ReasonPriority $reasonPriority,
    ) {
    }
}
