<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Backup\Queue\QueueClaimTokenSource;

final readonly class SystemQueueClaimTokenSource implements QueueClaimTokenSource
{
    public function next(): string
    {
        return random_bytes(16);
    }
}
