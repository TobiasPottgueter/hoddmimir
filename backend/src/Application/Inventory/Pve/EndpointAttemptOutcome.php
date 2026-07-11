<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

enum EndpointAttemptOutcome: string
{
    case Selected = 'selected';
    case Failover = 'failover';
    case Terminal = 'terminal';
}
