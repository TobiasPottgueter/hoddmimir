<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorLease;
use App\Application\Inventory\InventoryIdentifier;
use App\Domain\Scheduler\AutomaticReasonInputs;
use App\Domain\Scheduler\BackupCandidateWinnerSelector;
use App\Domain\Scheduler\ByteReasonEvidence;
use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\EligibleBackupCandidate;
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
use App\Domain\Policy\PolicyPriority;
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
        $runId = new ShadowEvaluationRunId($this->stableId('run', $lease->token->binary()));
        $committed = $this->persist->committedResult($lease, $runId, $this->evaluatorVersion);
        if (null !== $committed) {
            return $committed;
        }

        try {
            return $this->evaluate($lease, $runId);
        } catch (ShadowEvaluationConflict $conflict) {
            if (ShadowEvaluationConflictCode::ActiveRequestChanged !== $conflict->failureCode) {
                throw $conflict;
            }

            return $this->evaluate($lease, $runId);
        }
    }

    private function evaluate(
        CollectorLease $lease,
        ShadowEvaluationRunId $runId,
    ): ShadowEvaluationPersistenceResult {
        $startedAt = $this->clock->now();
        /** @var list<array{candidate: AutomaticShadowCandidate, decision: ShadowDecision}> $evaluated */
        $evaluated = [];
        foreach ($this->source->candidates($lease) as $candidate) {
            $evaluated[] = ['candidate' => $candidate, 'decision' => $this->decision($lease, $candidate, $startedAt)];
        }
        [$decisions, $promotions] = $this->selectWinners($evaluated);
        $finishedAt = $this->clock->now();
        $batch = new ShadowEvaluationBatch(
            $runId,
            $lease->token,
            $this->evaluatorVersion,
            $startedAt,
            $finishedAt,
            $decisions,
            $promotions,
        );

        return $this->persist->execute($lease, $batch);
    }

    /**
     * @param list<array{candidate: AutomaticShadowCandidate, decision: ShadowDecision}> $evaluated
     * @return array{list<ShadowDecision>, list<AutomaticShadowPromotion>}
     */
    private function selectWinners(array $evaluated): array
    {
        /** @var array<string, list<EligibleBackupCandidate>> $eligibleByGuest */
        $eligibleByGuest = [];
        /** @var array<string, AutomaticShadowCandidate> $candidateByDecision */
        $candidateByDecision = [];
        foreach ($evaluated as $item) {
            $decision = $item['decision'];
            if (DecisionOutcome::Eligible !== $decision->outcome) {
                continue;
            }
            /** @var \App\Domain\Scheduler\ReasonPriority $reasonPriority Eligible decisions enforce this invariant. */
            $reasonPriority = $decision->reasonPriority;
            $reason = $reasonPriority->reason;
            $candidate = $item['candidate'];
            /** @var ShadowPolicyEvidence $policy Eligible decisions enforce this invariant. */
            $policy = $decision->policy;
            /** @var ShadowTargetEvidence $target Eligible decisions enforce this invariant. */
            $target = $decision->target;
            $eligible = new EligibleBackupCandidate(
                $decision->id->binary(),
                $decision->guestId->binary(),
                $policy->policyId->binary(),
                $target->targetId->binary(),
                $reason,
                new PolicyPriority($candidate->policyPriority),
            );
            $guest = bin2hex($eligible->guestId);
            $eligibleByGuest[$guest][] = $eligible;
            $candidateByDecision[bin2hex($eligible->decisionId)] = $candidate;
        }

        /** @var array<string, true> $winnerIds */
        $winnerIds = [];
        $selector = new BackupCandidateWinnerSelector();
        foreach ($eligibleByGuest as $candidates) {
            $selection = $selector->select($candidates);
            $winnerIds[bin2hex($selection->winner->decisionId)] = true;
        }

        $decisions = [];
        $promotions = [];
        foreach ($evaluated as $item) {
            $decision = $item['decision'];
            $decisionHex = $decision->id->toHex();
            if (DecisionOutcome::Eligible !== $decision->outcome) {
                $decisions[] = $decision;
                continue;
            }
            if (!isset($winnerIds[$decisionHex])) {
                $decisions[] = $this->discardedDecision($decision);
                continue;
            }

            $candidate = $candidateByDecision[$decisionHex];
            $json = $candidate->resolvedPolicyJson;
            if (null === $json) {
                throw new \LogicException('Eligible automatic shadow promotion requires resolved policy JSON.');
            }
            /** @var ShadowPolicyEvidence $policy Eligible decisions enforce this invariant. */
            $policy = $decision->policy;
            $decisions[] = $decision;
            $promotions[] = new AutomaticShadowPromotion(
                $this->stableId('automatic-request', $decision->id->binary()),
                $decision->id->binary(),
                $json,
                $policy->snapshotHash(),
            );
        }

        return [$decisions, $promotions];
    }

    private function discardedDecision(ShadowDecision $decision): ShadowDecision
    {
        $gates = $decision->gates;
        $gates[] = new OrderedShadowGate(
            count($gates) + 1,
            new GateResult(
                GateCode::HigherRankedCandidateAbsent,
                false,
                GateScope::Request,
                $this->subject($decision->guestId->binary()),
                null,
                GateDetailCode::HigherRankedCandidate,
            ),
        );

        return new ShadowDecision(
            $decision->id,
            $decision->connectionId,
            $decision->clusterId,
            $decision->guestId,
            $decision->placement,
            DecisionOutcome::Deduplicated,
            $decision->reasonPriority,
            $decision->policy,
            $decision->target,
            $decision->inventoryObservedAt,
            $decision->capacityObservedAt,
            $decision->writeStateObservedAt,
            $gates,
        );
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
            $this->flag(
                GateCode::MinimumFreeSpace,
                GateScope::Capacity,
                $target,
                null !== $candidate->availableBytes
                    && null !== $candidate->minimumFreeBytes
                    && $candidate->expectedBackupSizePresent
                    && $candidate->minimumFreeBytes->lessThanOrEqual($candidate->availableBytes),
                null === $candidate->availableBytes
                    || null === $candidate->minimumFreeBytes
                    || !$candidate->expectedBackupSizePresent
                    ? GateDetailCode::Missing
                    : GateDetailCode::InsufficientFreeSpace,
            ),
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
