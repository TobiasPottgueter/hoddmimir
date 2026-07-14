<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorLease;
use App\Application\Inventory\InventoryIdentifier;
use App\Domain\Scheduler\AutomaticReasonInputs;
use App\Domain\Scheduler\ByteReasonEvidence;
use App\Domain\Scheduler\EligibilityEvaluator;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateResult;
use App\Domain\Scheduler\GateScope;
use App\Domain\Scheduler\GateSubjectId;
use App\Domain\Scheduler\PriorityResolver;
use App\Domain\Scheduler\ReasonSelector;
use App\Domain\Scheduler\RequestOrigin;
use App\Domain\Shared\Clock;
use DateInterval;
use DateTimeImmutable;

final readonly class RunAutomaticShadowEvaluation implements AutomaticShadowEvaluator
{
    public function __construct(
        private AutomaticShadowEvaluationSource $source,
        private PersistShadowEvaluation $persist,
        private EligibilityEvaluator $eligibility,
        private EvidenceFreshnessPolicy $freshness,
        private ReasonSelector $reasonSelector,
        private PriorityResolver $priorityResolver,
        private Clock $clock,
        private int $evaluatorVersion = 1,
    ) {
    }

    public function execute(CollectorLease $lease): ShadowEvaluationPersistenceResult
    {
        $startedAt = $this->clock->now();
        $decisions = [];
        foreach ($this->source->candidates($lease) as $candidate) {
            $decisions[] = $this->decision($lease, $candidate, $startedAt);
        }
        $finishedAt = $this->clock->now();
        $batch = new ShadowEvaluationBatch(
            new ShadowEvaluationRunId($this->stableId('run', $lease->token->binary())),
            $lease->token,
            $this->evaluatorVersion,
            $startedAt,
            $finishedAt,
            $decisions,
        );

        return $this->persist->execute($lease, $batch);
    }

    private function decision(
        CollectorLease $lease,
        AutomaticShadowCandidate $candidate,
        DateTimeImmutable $now,
    ): ShadowDecision {
        $connection = $this->subject($candidate->connectionId);
        $cluster = $this->subject($candidate->clusterId);
        $guest = $this->subject($candidate->guestId);
        $policy = $this->subject($candidate->policyId);
        $target = $this->subject($candidate->targetId);
        $node = $this->subject($candidate->nodeId ?? $candidate->guestId);
        $gates = [
            $this->flag(GateCode::ConnectionEnabled, GateScope::Connection, $connection, $candidate->connectionEnabled, GateDetailCode::Disabled),
            $this->flag(GateCode::ClusterEnabled, GateScope::Cluster, $cluster, $candidate->clusterEnabled, GateDetailCode::Archived),
            $this->flag(GateCode::NodeEnabled, GateScope::Node, $node, null !== $candidate->nodeId && $candidate->nodeEnabled, null === $candidate->nodeId ? GateDetailCode::Missing : GateDetailCode::Disabled),
            $this->flag(GateCode::GuestEnabled, GateScope::Guest, $guest, $candidate->selectionIncluded, GateDetailCode::Disabled),
            $this->flag(GateCode::PolicyEnabled, GateScope::Policy, $policy, $candidate->policyEnabled, GateDetailCode::Disabled),
            $this->flag(GateCode::TargetEnabled, GateScope::Target, $target, $candidate->targetEnabled, GateDetailCode::Disabled),
            $this->flag(GateCode::ExplicitExclusionAbsent, GateScope::Guest, $guest, !$candidate->explicitlyExcluded, GateDetailCode::ExplicitlyExcluded),
            $this->flag(GateCode::GuestActive, GateScope::Guest, $guest, $candidate->guestActive && false === $candidate->guestTemplate, $candidate->guestActive ? (null === $candidate->guestTemplate ? GateDetailCode::Missing : GateDetailCode::Inactive) : GateDetailCode::Archived),
            $this->freshness->evaluate(GateCode::InventoryFresh, GateScope::Inventory, $guest, $now, $candidate->inventoryObservedAt),
            $this->flag(GateCode::PlacementPresent, GateScope::Placement, $guest, null !== $candidate->nodeId, GateDetailCode::Missing),
            $this->freshness->evaluate(GateCode::PlacementFresh, GateScope::Placement, $guest, $now, $candidate->placementObservedAt),
            $this->flag(GateCode::TargetNodeAllowed, GateScope::Target, $node, $candidate->targetNodeAllowed, GateDetailCode::NotAllowed),
            $this->flag(GateCode::TargetStorageEnabled, GateScope::Target, $target, $candidate->storageEnabled, GateDetailCode::Disabled),
            $this->flag(GateCode::TargetStorageActive, GateScope::Target, $target, $candidate->storageActive, GateDetailCode::Inactive),
            $this->freshness->evaluate(GateCode::CapacityFresh, GateScope::Capacity, $target, $now, $candidate->capacityObservedAt),
            $this->flag(GateCode::MinimumFreeSpace, GateScope::Capacity, $target, null !== $candidate->availableBytes && null !== $candidate->minimumFreeBytes && $candidate->minimumFreeBytes->lessThanOrEqual($candidate->availableBytes), null === $candidate->availableBytes ? GateDetailCode::Missing : GateDetailCode::InsufficientFreeSpace),
            $this->flag(GateCode::NodeConcurrency, GateScope::Concurrency, $node, $candidate->nodeConcurrencyAvailable, GateDetailCode::ConcurrencyLimitReached),
            $this->flag(GateCode::TargetConcurrency, GateScope::Concurrency, $target, $candidate->targetConcurrencyAvailable, GateDetailCode::ConcurrencyLimitReached),
            $this->flag(GateCode::PbsMappingValid, GateScope::PbsMapping, $target, $candidate->pbsMappingValid, GateDetailCode::InvalidMapping),
            $this->freshness->evaluate(GateCode::InventoryFresh, GateScope::PbsMapping, $target, $now, $candidate->pbsObservedAt),
            $this->freshness->evaluate(GateCode::ExecutorAuthorizationFresh, GateScope::Authorization, $target, $now, $candidate->executorObservedAt),
            $this->flag(GateCode::ExecutorAuthorized, GateScope::Authorization, $target, $candidate->executorAuthorized, null === $candidate->executorObservedAt ? GateDetailCode::Missing : GateDetailCode::Unauthorized),
            $this->flag(GateCode::PolicyRetentionCompatible, GateScope::Policy, $policy, $candidate->policyRetentionCompatible, GateDetailCode::Incompatible),
            $this->flag(GateCode::ActiveRequestAbsent, GateScope::Request, $guest, $candidate->activeRequestAbsent, GateDetailCode::ActiveRequestExists),
        ];

        $reason = $this->reason($lease, $candidate, $now);
        $reasonPriority = null === $reason ? null : $this->priorityResolver->resolve(RequestOrigin::Automatic, $reason, null);
        $eligibility = $this->eligibility->evaluate($gates, $reasonPriority);
        $ordered = \array_map(
            static fn (GateResult $gate, int $offset): OrderedShadowGate => new OrderedShadowGate($offset + 1, $gate),
            $gates,
            \array_keys($gates),
        );
        $placement = null === $candidate->nodeId || null === $candidate->placementRevision || null === $candidate->placementObservedAt
            ? null
            : new ShadowPlacementEvidence($this->inventory($candidate->nodeId), $candidate->placementRevision, $candidate->placementObservedAt);

        return new ShadowDecision(
            new ShadowDecisionId($this->stableId('decision', $lease->token->binary().$candidate->guestId.$candidate->policyId.$candidate->targetId)),
            $this->inventory($candidate->connectionId),
            $this->inventory($candidate->clusterId),
            $this->inventory($candidate->guestId),
            $placement,
            $eligibility->outcome,
            $eligibility->reasonPriority,
            new ShadowPolicyEvidence($this->inventory($candidate->policyId), $candidate->policyRevision, $candidate->policySnapshotHash),
            new ShadowTargetEvidence($this->inventory($candidate->targetId), $candidate->targetRevision),
            $candidate->inventoryObservedAt,
            $candidate->capacityObservedAt,
            $candidate->writeStateObservedAt,
            $ordered,
        );
    }

    private function reason(CollectorLease $lease, AutomaticShadowCandidate $candidate, DateTimeImmutable $now): ?\App\Domain\Scheduler\BackupReason
    {
        if (null === $candidate->lastSuccessAt) {
            return $this->reasonSelector->select(AutomaticReasonInputs::withoutSuccessfulBackup($now));
        }
        $boundary = null === $candidate->maximumAgeSeconds
            ? $now->add(new DateInterval('P100Y'))
            : $candidate->lastSuccessAt->add(new DateInterval('PT'.$candidate->maximumAgeSeconds.'S'));
        $bytes = null;
        if (null !== $candidate->currentBytes && null !== $candidate->baselineBytes
            && !$candidate->baselineBytes->lessThanOrEqual($candidate->currentBytes)) {
            $this->source->recordCounterReset($lease, $candidate, $now);
        } elseif (null !== $candidate->currentBytes && null !== $candidate->baselineBytes
            && null !== $candidate->bytesThreshold && null !== $candidate->cooldownSeconds) {
            $bytes = ByteReasonEvidence::fromCounters(
                $candidate->lastSuccessAt->add(new DateInterval('PT'.$candidate->cooldownSeconds.'S')),
                $candidate->currentBytes->value,
                $candidate->baselineBytes->value,
                $candidate->bytesThreshold->value,
            );
        }

        return $this->reasonSelector->select(AutomaticReasonInputs::withSuccessfulBackup(
            $now,
            $candidate->lastSuccessAt,
            $boundary,
            $bytes,
        ));
    }

    private function flag(GateCode $code, GateScope $scope, GateSubjectId $subject, bool $passed, GateDetailCode $failure): GateResult
    {
        return new GateResult($code, $passed, $scope, $subject, null, $passed ? GateDetailCode::Passed : $failure);
    }

    private function subject(string $bytes): GateSubjectId { return new GateSubjectId($bytes); }
    private function inventory(string $bytes): InventoryIdentifier { return new InventoryIdentifier($bytes); }
    private function stableId(string $context, string $bytes): string { return \substr(\hash('sha256', $context."\0".$bytes, true), 0, 16); }
}
