<?php

declare(strict_types=1);

namespace App\Domain\Worker;

enum WorkerKind: string
{
    case Collector = 'collector';
    case Backup = 'backup';
}
