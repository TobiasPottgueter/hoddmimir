<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

interface QueueClaimTokenSource
{
    public function next(): string;
}
