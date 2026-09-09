<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

enum DecisionOutcome: string
{
    case Eligible = 'eligible';
    case Blocked = 'blocked';
    case NotDue = 'not_due';
    case Deduplicated = 'deduplicated';
}
