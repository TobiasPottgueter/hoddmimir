<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

enum BackupTargetExecutorStatus: string
{
    case RequiresTargetConfiguration = 'requires_target_configuration';
    case Missing = 'missing';
    case Partial = 'partial';
    case Authorized = 'authorized';
    case Unauthorized = 'unauthorized';
}
