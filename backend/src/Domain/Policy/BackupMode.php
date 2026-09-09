<?php

declare(strict_types=1);

namespace App\Domain\Policy;

enum BackupMode: string
{
    case Snapshot = 'snapshot';
    case Suspend = 'suspend';
    case Stop = 'stop';
}
