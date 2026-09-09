<?php

declare(strict_types=1);

namespace App\Domain\Target;

final readonly class TargetActivationEvidence
{
    public function __construct(
        public ActivationEvidenceObservation $candidate,
        public ActivationEvidenceObservation $inventory,
        public ActivationEvidenceObservation $capacity,
        public ActivationEvidenceObservation $executor,
    ) {
    }
}
