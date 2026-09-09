<?php

declare(strict_types=1);

namespace App\Application\Configuration;

enum ConfigurationCommandStatus: string
{
    case Applied = 'applied';
    case Replayed = 'replayed';
    case Conflict = 'conflict';
    case Blocked = 'blocked';
    case Denied = 'denied';
}
