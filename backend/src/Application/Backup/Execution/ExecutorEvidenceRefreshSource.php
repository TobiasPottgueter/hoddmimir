<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

interface ExecutorEvidenceRefreshSource
{
    /** @throws ExecutorEvidenceRefreshFailure */
    public function read(
        ExecutorEvidenceRefreshClaim $claim,
        ExecutorEvidenceRefreshEndpoint $endpoint,
    ): PveExecutorPermissionSnapshot;
}
