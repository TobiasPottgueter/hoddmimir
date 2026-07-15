<?php

declare(strict_types=1);

namespace App\Application\Configuration\Policy;

use App\Domain\Target\ActivationEvidenceObservation;
use DateTimeImmutable;
use DateTimeZone;

final readonly class PolicyActivationEvidence
{
    public function __construct(
        public ?int $pveMajor,
        ?DateTimeImmutable $pveObservedAt,
        public ActivationEvidenceObservation $target,
        public ActivationEvidenceObservation $executor,
        public bool $pbsTarget = false,
        public bool $retentionExecutionEnabled = false,
        public ?bool $targetEnabled = null,
    ) {
        $this->pveObservedAt = $pveObservedAt?->setTimezone(new DateTimeZone('UTC'));
    }

    public readonly ?DateTimeImmutable $pveObservedAt;
}
