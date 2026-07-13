<?php

declare(strict_types=1);

namespace App\Domain\Target;

use DomainException;
use DateTimeImmutable;

final readonly class BackupTarget
{
    public function __construct(
        public BackupTargetId $id,
        public TargetRevision $revision,
        public TargetStatus $status,
        public bool $pbsStorage,
        public ?MinimumFreeBytes $minimumFree,
        public AllowedNodes $allowedNodes,
        public ?ConcurrencyPolicy $concurrency,
        public ?PbsTargetMapping $pbsMapping,
    ) {
    }

    public function assessActivation(TargetActivationEvidence $evidence, DateTimeImmutable $now, int $maximumAgeSeconds = 300): TargetActivationAssessment
    {
        $blockers = [];
        if (null === $this->minimumFree) {
            $blockers[] = TargetActivationBlocker::MinimumFreeUnconfigured;
        }
        if ($this->allowedNodes->empty()) {
            $blockers[] = TargetActivationBlocker::AllowedNodesEmpty;
        }
        if (null === $this->concurrency) {
            $blockers[] = TargetActivationBlocker::ConcurrencyUnconfigured;
        }
        if ($this->pbsStorage && null === $this->pbsMapping) {
            $blockers[] = TargetActivationBlocker::PbsMappingRequired;
        }
        if (!$this->pbsStorage && null !== $this->pbsMapping) {
            $blockers[] = TargetActivationBlocker::PbsMappingUnexpected;
        }
        array_push($blockers, ...$this->evidenceBlockers($evidence->candidate, $now, $maximumAgeSeconds,
            TargetActivationBlocker::CandidateEvidenceMissing, TargetActivationBlocker::CandidateEvidenceStale,
            TargetActivationBlocker::CandidateEvidenceFuture, TargetActivationBlocker::CandidateRejected));
        array_push($blockers, ...$this->evidenceBlockers($evidence->inventory, $now, $maximumAgeSeconds,
            TargetActivationBlocker::InventoryEvidenceMissing, TargetActivationBlocker::InventoryEvidenceStale,
            TargetActivationBlocker::InventoryEvidenceFuture, TargetActivationBlocker::CandidateRejected));
        array_push($blockers, ...$this->evidenceBlockers($evidence->capacity, $now, $maximumAgeSeconds,
            TargetActivationBlocker::CapacityEvidenceMissing, TargetActivationBlocker::CapacityEvidenceStale,
            TargetActivationBlocker::CapacityEvidenceFuture, TargetActivationBlocker::CandidateRejected));
        array_push($blockers, ...$this->evidenceBlockers($evidence->executor, $now, $maximumAgeSeconds,
            TargetActivationBlocker::ExecutorEvidenceMissing, TargetActivationBlocker::ExecutorEvidenceStale,
            TargetActivationBlocker::ExecutorEvidenceFuture, TargetActivationBlocker::ExecutorUnauthorized));

        return new TargetActivationAssessment($blockers);
    }

    public function enable(TargetActivationEvidence $evidence, DateTimeImmutable $now, int $maximumAgeSeconds = 300): self
    {
        if ($this->status->executable()) {
            throw new DomainException('An enabled backup target cannot be enabled again.');
        }
        $assessment = $this->assessActivation($evidence, $now, $maximumAgeSeconds);
        if (!$assessment->canEnable()) {
            /** @var non-empty-list<TargetActivationBlocker> $activationBlockers */
            $activationBlockers = $assessment->blockers;
            throw new TargetActivationFailed($activationBlockers);
        }

        return new self($this->id, $this->revision->next(), TargetStatus::Enabled, $this->pbsStorage,
            $this->minimumFree, $this->allowedNodes, $this->concurrency, $this->pbsMapping);
    }

    public function disable(): self
    {
        if (!$this->status->executable()) {
            throw new DomainException('Only an enabled backup target can be disabled.');
        }

        return new self($this->id, $this->revision->next(), TargetStatus::Disabled, $this->pbsStorage,
            $this->minimumFree, $this->allowedNodes, $this->concurrency, $this->pbsMapping);
    }

    /** @return list<TargetActivationBlocker> */
    private function evidenceBlockers(
        ActivationEvidenceObservation $observation,
        DateTimeImmutable $now,
        int $maximumAgeSeconds,
        TargetActivationBlocker $missing,
        TargetActivationBlocker $stale,
        TargetActivationBlocker $future,
        TargetActivationBlocker $rejected,
    ): array {
        $freshness = $observation->freshness($now, $maximumAgeSeconds);
        if (EvidenceObservationFreshness::Missing === $freshness) {
            return [$missing];
        }
        if (EvidenceObservationFreshness::Stale === $freshness) {
            return [$stale];
        }
        if (EvidenceObservationFreshness::Future === $freshness) {
            return [$future];
        }
        return true === $observation->accepted ? [] : [$rejected];
    }
}
