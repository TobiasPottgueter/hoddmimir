<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

enum RequestOrigin: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Retry = 'retry';
}
