<?php

declare(strict_types=1);

namespace App\Application\Configuration\Policy;

use App\Domain\Policy\PolicyId;

interface PolicyActivationEvidenceProvider
{
    public function policyEvidence(PolicyId $id): PolicyActivationEvidence;
}
