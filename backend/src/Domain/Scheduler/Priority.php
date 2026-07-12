<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

enum Priority: int
{
    case Manual = 400;
    case NeverBackedUp = 300;
    case MaxAge = 200;
    case BytesWritten = 100;
}
