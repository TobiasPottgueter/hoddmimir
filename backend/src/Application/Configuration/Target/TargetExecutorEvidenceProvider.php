<?php

declare(strict_types=1);

namespace App\Application\Configuration\Target;

use App\Domain\Target\ActivationEvidenceObservation;
use App\Domain\Target\BackupTargetId;

interface TargetExecutorEvidenceProvider
{
    public function executorEvidence(BackupTargetId $id): ActivationEvidenceObservation;

    /**
     * @param list<BackupTargetId> $ids
     * @return array<string, ActivationEvidenceObservation> keyed by BackupTargetId::toHex()
     */
    public function executorEvidenceBatch(array $ids): array;
}
