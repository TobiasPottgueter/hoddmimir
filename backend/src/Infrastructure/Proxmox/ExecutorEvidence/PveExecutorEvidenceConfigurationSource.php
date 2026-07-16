<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshClaim;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshEndpoint;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;

interface PveExecutorEvidenceConfigurationSource
{
    /** @throws ExecutorEvidenceRefreshFailure */
    public function load(
        ExecutorEvidenceRefreshClaim $claim,
        ExecutorEvidenceRefreshEndpoint $endpoint,
    ): PveExecutorEvidenceEndpointConfiguration;
}
