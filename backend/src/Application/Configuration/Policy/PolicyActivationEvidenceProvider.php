<?php

declare(strict_types=1);

namespace App\Application\Configuration\Policy;

use App\Domain\Policy\PolicyId;

interface PolicyActivationEvidenceProvider
{
    public function policyEvidence(PolicyId $id): PolicyActivationEvidence;

    /**
     * @param list<PolicyId> $ids
     * @return array<string, PolicyActivationEvidence> keyed by lowercase binary ID hex
     */
    public function policyEvidenceBatch(array $ids): array;
}
