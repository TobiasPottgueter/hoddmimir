<?php

declare(strict_types=1);

namespace App\Application\Configuration\Target;

use App\Domain\Target\ActivationEvidenceObservation;

final readonly class TargetCandidateEvidence
{
    public function __construct(
        public ActivationEvidenceObservation $candidate,
        public ActivationEvidenceObservation $inventory,
        public ActivationEvidenceObservation $capacity,
    ) {
    }
}
