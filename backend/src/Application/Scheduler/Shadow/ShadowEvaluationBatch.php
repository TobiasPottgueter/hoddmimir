<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorCycleToken;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final readonly class ShadowEvaluationBatch
{
    /** @var list<ShadowDecision> */
    public array $decisions;
    /** @var list<AutomaticShadowPromotion> */
    public array $promotions;
    public DateTimeImmutable $startedAt;
    public DateTimeImmutable $finishedAt;

    /** @param list<ShadowDecision> $decisions
     *  @param list<AutomaticShadowPromotion> $promotions
     */
    public function __construct(
        public ShadowEvaluationRunId $runId,
        public CollectorCycleToken $cycleToken,
        public int $evaluatorVersion,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
        array $decisions,
        array $promotions = [],
    ) {
        if ($this->evaluatorVersion < 1 || $this->evaluatorVersion > 65535) {
            throw new InvalidArgumentException('A shadow evaluator version must fit an unsigned small integer.');
        }
        $utc = new DateTimeZone('UTC');
        $this->startedAt = $startedAt->setTimezone($utc);
        $this->finishedAt = $finishedAt->setTimezone($utc);
        if ($this->finishedAt < $this->startedAt) {
            throw new InvalidArgumentException('A shadow evaluation cannot finish before it started.');
        }

        $byId = [];
        foreach ($decisions as $decision) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$decision instanceof ShadowDecision || isset($byId[$decision->id->toHex()])) {
                throw new InvalidArgumentException('Shadow evaluation decisions must have unique valid IDs.');
            }
            $byId[$decision->id->toHex()] = $decision;
        }
        ksort($byId, SORT_STRING);
        $this->decisions = array_values($byId);

        $promotionByDecision = [];
        $promotedGuests = [];
        foreach ($promotions as $promotion) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$promotion instanceof AutomaticShadowPromotion) {
                throw new InvalidArgumentException('Shadow promotion payloads are invalid.');
            }
            $decisionHex = bin2hex($promotion->decisionId);
            $decision = $byId[$decisionHex] ?? null;
            if (null === $decision || \App\Domain\Scheduler\DecisionOutcome::Eligible !== $decision->outcome
                || isset($promotionByDecision[$decisionHex])) {
                throw new InvalidArgumentException('Every automatic promotion must reference one unique eligible decision.');
            }
            $guestHex = bin2hex($decision->guestId->binary());
            if (isset($promotedGuests[$guestHex])) {
                throw new InvalidArgumentException('A shadow batch may promote only one winner per guest.');
            }
            $policyHash = $decision->policy?->snapshotHash();
            if (null === $policyHash || !hash_equals($policyHash, $promotion->resolvedPolicyHash())) {
                throw new InvalidArgumentException('The promotion policy evidence differs from its shadow decision.');
            }
            $promotionByDecision[$decisionHex] = $promotion;
            $promotedGuests[$guestHex] = true;
        }
        ksort($promotionByDecision, SORT_STRING);
        $this->promotions = array_values($promotionByDecision);
    }

    /** @throws JsonException */
    public function contentHash(): string
    {
        return hash('sha256', json_encode($this->canonicalDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }

    public function gateCount(): int
    {
        return array_sum(array_map(static fn (ShadowDecision $decision): int => count($decision->gates), $this->decisions));
    }

    /** @return array<string, mixed> */
    private function canonicalDocument(): array
    {
        return [
            'run_id' => $this->runId->toHex(),
            'cycle_token' => bin2hex($this->cycleToken->binary()),
            'evaluator_version' => $this->evaluatorVersion,
            'started_at' => $this->format($this->startedAt),
            'finished_at' => $this->format($this->finishedAt),
            'decisions' => array_map(fn (ShadowDecision $decision): array => $this->decisionDocument($decision), $this->decisions),
            'promotions' => array_map(static fn (AutomaticShadowPromotion $promotion): array => [
                'request_id' => bin2hex($promotion->requestId),
                'decision_id' => bin2hex($promotion->decisionId),
                'resolved_policy_json' => $promotion->resolvedPolicyJson,
                'resolved_policy_hash' => $promotion->resolvedPolicyHashHex(),
            ], $this->promotions),
        ];
    }

    /** @return array<string, mixed> */
    private function decisionDocument(ShadowDecision $decision): array
    {
        return [
            'id' => $decision->id->toHex(),
            'connection_id' => bin2hex($decision->connectionId->binary()),
            'cluster_id' => bin2hex($decision->clusterId->binary()),
            'guest_id' => bin2hex($decision->guestId->binary()),
            'node_id' => null === $decision->placement ? null : bin2hex($decision->placement->nodeId->binary()),
            'placement_revision' => $decision->placement?->revision,
            'placement_observed_at' => null === $decision->placement
                ? null : $this->format($decision->placement->observedAt),
            'outcome' => $decision->outcome->value,
            'reason' => $decision->reasonPriority?->reason->value,
            'priority' => $decision->reasonPriority?->priority->value,
            'policy_id' => null === $decision->policy ? null : bin2hex($decision->policy->policyId->binary()),
            'policy_revision' => $decision->policy?->revision,
            'policy_snapshot_hash' => $decision->policy?->snapshotHashHex(),
            'target_id' => null === $decision->target ? null : bin2hex($decision->target->targetId->binary()),
            'target_revision' => $decision->target?->revision,
            'inventory_observed_at' => $this->format($decision->inventoryObservedAt),
            'capacity_observed_at' => $this->optionalDate($decision->capacityObservedAt),
            'write_state_observed_at' => $this->optionalDate($decision->writeStateObservedAt),
            'gates' => array_map(static fn (OrderedShadowGate $gate): array => [
                'position' => $gate->position,
                'code' => $gate->result->code->value,
                'passed' => $gate->result->passed,
                'scope' => $gate->result->scope->value,
                'subject_id' => bin2hex($gate->result->subjectId->binary()),
                'observed_at' => null === $gate->result->observedAt
                    ? null : $gate->result->observedAt->format('Y-m-d\TH:i:s.u\Z'),
                'detail_code' => $gate->result->detailCode->value,
            ], $decision->gates),
        ];
    }

    private function optionalDate(?DateTimeImmutable $value): ?string
    {
        return null === $value ? null : $this->format($value);
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.u\Z');
    }
}
