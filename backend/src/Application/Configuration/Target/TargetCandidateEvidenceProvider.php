<?php

declare(strict_types=1);

namespace App\Application\Configuration\Target;

use App\Domain\Target\BackupTargetId;

interface TargetCandidateEvidenceProvider
{
    public function candidateEvidence(BackupTargetId $id): TargetCandidateEvidence;

    /**
     * @param list<BackupTargetId> $ids
     * @return array<string, TargetCandidateEvidence> keyed by BackupTargetId::toHex()
     */
    public function candidateEvidenceBatch(array $ids): array;
}
