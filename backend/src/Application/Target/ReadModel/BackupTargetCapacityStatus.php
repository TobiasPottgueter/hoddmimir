<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

enum BackupTargetCapacityStatus: string
{
    case Missing = 'missing';
    case Measured = 'measured';
    case Unavailable = 'unavailable';
    case Invalid = 'invalid';
}
