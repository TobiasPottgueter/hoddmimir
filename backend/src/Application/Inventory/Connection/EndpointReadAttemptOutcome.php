<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

enum EndpointReadAttemptOutcome: string
{
    case Selected = 'selected';
    case Failover = 'failover';
    case Terminal = 'terminal';
}
