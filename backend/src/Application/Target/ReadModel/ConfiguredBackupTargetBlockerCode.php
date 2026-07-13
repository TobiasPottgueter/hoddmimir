<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

enum ConfiguredBackupTargetBlockerCode: string
{
    case ConfigurationIncomplete = 'configuration_incomplete';
    case PbsBindingMissing = 'pbs_binding_missing';
    case ExecutorEvidenceMissing = 'executor_evidence_missing';
}
