<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use RuntimeException;

final class ExecutorEvidenceLeaseOwnershipLost extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Executor permission evidence lease ownership was lost.');
    }
}
