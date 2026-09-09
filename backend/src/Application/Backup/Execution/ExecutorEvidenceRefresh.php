<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

interface ExecutorEvidenceRefresh
{
    public function refreshDue(string $workerId): ExecutorEvidenceRefreshStatus;
}
