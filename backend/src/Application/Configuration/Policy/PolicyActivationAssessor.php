<?php

declare(strict_types=1);

namespace App\Application\Configuration\Policy;

use App\Domain\Policy\BackupPolicy;
use App\Domain\Target\ActivationEvidenceObservation;
use App\Domain\Target\EvidenceObservationFreshness;
use DateTimeImmutable;

final readonly class PolicyActivationAssessor
{
    public function assess(
        BackupPolicy $policy,
        PolicyActivationEvidence $evidence,
        DateTimeImmutable $now,
        int $maximumAgeSeconds = 300,
    ): PolicyActivationAssessment {
        $blockers = [];
        if (null === $evidence->pveMajor || null === $evidence->pveObservedAt) {
            $blockers[] = PolicyActivationBlockerCode::PveEvidenceMissing;
        } else {
            $pve = new ActivationEvidenceObservation(true, $evidence->pveObservedAt);
            $freshness = $pve->freshness($now, $maximumAgeSeconds);
            if (EvidenceObservationFreshness::Future === $freshness) {
                $blockers[] = PolicyActivationBlockerCode::PveEvidenceFuture;
            } elseif (EvidenceObservationFreshness::Stale === $freshness) {
                $blockers[] = PolicyActivationBlockerCode::PveEvidenceStale;
            } elseif ($evidence->pveMajor < 7 || $evidence->pveMajor > 9) {
                $blockers[] = PolicyActivationBlockerCode::UnsupportedPveMajor;
            }
        }
        if (false === $evidence->targetEnabled) {
            $blockers[] = PolicyActivationBlockerCode::TargetDisabled;
        }
        array_push($blockers, ...$this->observationBlockers($evidence->target, $now, $maximumAgeSeconds, true));
        array_push($blockers, ...$this->observationBlockers($evidence->executor, $now, $maximumAgeSeconds, false));
        if ($evidence->pbsTarget && $evidence->retentionExecutionEnabled) {
            $blockers[] = PolicyActivationBlockerCode::RetentionExecutionForbiddenForPbsTarget;
        }
        $policyBlockers = null === $evidence->pveMajor
            ? $policy->configurationBlockers()
            : $policy->activationBlockers($evidence->pveMajor);
        foreach ($policyBlockers as $blocker) {
            $blockers[] = PolicyActivationBlockerCode::from($blocker->value);
        }

        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->value] = $blocker;
        }
        return new PolicyActivationAssessment(array_values($unique));
    }

    /** @return list<PolicyActivationBlockerCode> */
    private function observationBlockers(
        ActivationEvidenceObservation $observation,
        DateTimeImmutable $now,
        int $maximumAgeSeconds,
        bool $target,
    ): array {
        $freshness = $observation->freshness($now, $maximumAgeSeconds);
        if (EvidenceObservationFreshness::Missing === $freshness) {
            return [$target
                ? PolicyActivationBlockerCode::TargetEvidenceMissing
                : PolicyActivationBlockerCode::ExecutorEvidenceMissing];
        }
        if (EvidenceObservationFreshness::Stale === $freshness) {
            return [$target
                ? PolicyActivationBlockerCode::TargetEvidenceStale
                : PolicyActivationBlockerCode::ExecutorEvidenceStale];
        }
        if (EvidenceObservationFreshness::Future === $freshness) {
            return [$target
                ? PolicyActivationBlockerCode::TargetEvidenceFuture
                : PolicyActivationBlockerCode::ExecutorEvidenceFuture];
        }

        return $this->freshObservationBlockers($observation, $target);
    }

    /** @return list<PolicyActivationBlockerCode> */
    private function freshObservationBlockers(ActivationEvidenceObservation $observation, bool $target): array
    {
        if (true === $observation->accepted) {
            return [];
        }

        return [$target
            ? PolicyActivationBlockerCode::TargetDisabled
            : PolicyActivationBlockerCode::ExecutorUnauthorized];
    }
}
