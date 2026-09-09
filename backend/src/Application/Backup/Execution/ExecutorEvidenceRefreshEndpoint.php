<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use InvalidArgumentException;

final readonly class ExecutorEvidenceRefreshEndpoint
{
    public function __construct(public string $id, public int $priority)
    {
        if (16 !== \strlen($id) || $priority < 0 || $priority > 65535) {
            throw new InvalidArgumentException('Executor evidence refresh endpoint is invalid.');
        }
    }
}
