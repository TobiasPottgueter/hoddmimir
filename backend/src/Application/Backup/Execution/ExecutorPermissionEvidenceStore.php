<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

interface ExecutorPermissionEvidenceStore
{
    public function persist(ExecutorPermissionEvidence $evidence): void;
}
