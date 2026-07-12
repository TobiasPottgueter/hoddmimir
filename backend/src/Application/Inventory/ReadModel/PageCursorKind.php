<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

enum PageCursorKind: string
{
    case Resource = 'resource';
    case CollectorRun = 'collector_run';
    case CollectorScope = 'collector_scope';
}
