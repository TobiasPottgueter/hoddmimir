<?php

declare(strict_types=1);

namespace App\Application\Policy\ReadModel;

enum PolicyBlockerCode: string
{
    case ConfigurationIncomplete = 'configuration_incomplete';
    case ExecutorEvidenceMissing = 'executor_evidence_missing';
    case RetentionExecutionForbiddenForPbsTarget = 'retention_execution_forbidden_for_pbs_target';
}
