<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution\ReadModel;

interface ExecutorPermissionEvidenceReadModel
{
    public function evidence(ExecutorPermissionEvidenceQuery $query): ExecutorPermissionEvidencePage;
}
