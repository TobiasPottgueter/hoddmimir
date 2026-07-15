<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Scheduler\Shadow\AutomaticShadowCandidate;
use App\Application\Scheduler\Shadow\AutomaticShadowEvaluationSource;
use App\Application\Scheduler\Shadow\PersistShadowEvaluation;
use App\Application\Scheduler\Shadow\RunAutomaticShadowEvaluation;
use App\Application\Scheduler\Shadow\ShadowEvaluationBatch;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflict;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflictCode;
use App\Application\Scheduler\Shadow\ShadowEvaluationPersistenceResult;
use App\Application\Scheduler\Shadow\ShadowEvaluationRunId;
use App\Application\Scheduler\Shadow\ShadowEvaluationStore;
use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\EligibilityEvaluator;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Scheduler\PriorityResolver;
use App\Domain\Scheduler\ReasonSelector;
use App\Domain\Shared\Clock;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RunAutomaticShadowEvaluationTest extends TestCase
{
    public function testMissingExecutorEvidenceBlocksAndPersistsAllGatesInStableOrder(): void
    {
        $source = new RecordingAutomaticShadowSource([$this->candidate(executorObservedAt: null, executorAuthorized: false)]);
        $store = new CapturingShadowStore();
        $service = $this->service($source, $store, new DateTimeImmutable('2026-07-12T10:05:00Z'));

        self::assertSame(ShadowEvaluationPersistenceResult::Persisted, $service->execute($this->lease()));
        $decision = $store->batches[0]->decisions[0];
        self::assertSame(DecisionOutcome::Blocked, $decision->outcome);
        self::assertSame('never_backed_up', $decision->reasonPriority?->reason->value);
        self::assertSame([
            'connection_enabled', 'cluster_enabled', 'node_enabled', 'guest_enabled', 'policy_enabled',
            'target_enabled', 'explicit_exclusion_absent', 'guest_active', 'inventory_fresh',
            'placement_present', 'placement_fresh', 'target_node_allowed', 'target_storage_enabled',
            'target_storage_active', 'capacity_fresh', 'minimum_free_space', 'node_concurrency',
            'target_concurrency', 'pbs_mapping_valid',
            'inventory_fresh', 'executor_authorization_fresh', 'executor_authorized',
            'policy_retention_compatible', 'active_request_absent',
        ], array_map(static fn ($gate) => $gate->result->code->value, $decision->gates));
        self::assertSame('missing', $decision->gates[20]->result->detailCode->value);
        self::assertSame('missing', $decision->gates[21]->result->detailCode->value);
    }

    public function testEvidenceExactlyThreeHundredSecondsOldIsFreshAndIdsAreStable(): void
    {
        $source = new RecordingAutomaticShadowSource([$this->candidate()]);
        $store = new CapturingShadowStore();
        $service = $this->service($source, $store, new DateTimeImmutable('2026-07-12T10:05:00Z'));
        $service->execute($this->lease());
        $service->execute($this->lease());
        self::assertTrue($store->batches[0]->decisions[0]->gates[8]->result->passed);
        self::assertTrue($store->batches[0]->decisions[0]->gates[10]->result->passed);
        self::assertSame($store->batches[0]->runId->toHex(), $store->batches[1]->runId->toHex());
        self::assertSame($store->batches[0]->decisions[0]->id->toHex(), $store->batches[1]->decisions[0]->id->toHex());
    }

    public function testFreshAuthorizedExecutorEvidencePassesBothAuthorizationGates(): void
    {
        $store = new CapturingShadowStore();
        $this->service(
            new RecordingAutomaticShadowSource([$this->candidate()]),
            $store,
            new DateTimeImmutable('2026-07-12T10:05:00Z'),
        )->execute($this->lease());

        $decision = $store->batches[0]->decisions[0];
        self::assertSame(DecisionOutcome::Eligible, $decision->outcome);
        self::assertTrue($decision->gates[20]->result->passed);
        self::assertTrue($decision->gates[21]->result->passed);
        self::assertCount(1, $store->batches[0]->promotions);
        self::assertSame($decision->id->binary(), $store->batches[0]->promotions[0]->decisionId);
    }

    public function testPerGuestWinnerUsesReasonBeforePolicyPriorityAndExplainsTheLoser(): void
    {
        $store = new CapturingShadowStore();
        $this->service(new RecordingAutomaticShadowSource([
            $this->candidate(policyId: str_repeat('a', 16), policyPriority: 1),
            $this->candidate(
                lastSuccessAt: new DateTimeImmutable('2026-07-12T09:00:00Z'),
                maximumAgeSeconds: 3600,
                policyId: str_repeat('b', 16),
                policyPriority: 1000,
            ),
        ]), $store, new DateTimeImmutable('2026-07-12T10:05:00Z'))->execute($this->lease());

        $batch = $store->batches[0];
        self::assertCount(1, $batch->promotions);
        $winner = array_values(array_filter($batch->decisions, static fn ($decision): bool => DecisionOutcome::Eligible === $decision->outcome))[0];
        $loser = array_values(array_filter($batch->decisions, static fn ($decision): bool => DecisionOutcome::Deduplicated === $decision->outcome))[0];
        self::assertSame('never_backed_up', $winner->reasonPriority?->reason->value);
        self::assertSame($winner->id->binary(), $batch->promotions[0]->decisionId);
        self::assertSame('higher_ranked_candidate_absent', $loser->gates[24]->result->code->value);
        self::assertSame('higher_ranked_candidate', $loser->gates[24]->result->detailCode->value);
    }

    public function testPerGuestWinnerUsesPolicyPriorityThenStablePolicyId(): void
    {
        foreach ([
            'policy priority' => [
                $this->candidate(policyId: str_repeat('z', 16), policyPriority: 900),
                $this->candidate(policyId: str_repeat('a', 16), policyPriority: 100),
                str_repeat('z', 16),
            ],
            'stable policy id' => [
                $this->candidate(policyId: str_repeat('z', 16), policyPriority: 100),
                $this->candidate(policyId: str_repeat('a', 16), policyPriority: 100),
                str_repeat('a', 16),
            ],
        ] as [$left, $right, $expectedPolicy]) {
            $store = new CapturingShadowStore();
            $this->service(new RecordingAutomaticShadowSource([$left, $right]), $store, new DateTimeImmutable('2026-07-12T10:05:00Z'))->execute($this->lease());
            $winner = array_values(array_filter($store->batches[0]->decisions, static fn ($decision): bool => DecisionOutcome::Eligible === $decision->outcome))[0];
            self::assertSame($expectedPolicy, $winner->policy?->policyId->binary());
            self::assertCount(1, $store->batches[0]->promotions);
        }
    }

    public function testStaleAndUnauthorizedExecutorEvidenceRemainDistinctClosedBlockers(): void
    {
        foreach ([
            'stale' => [new DateTimeImmutable('2026-07-12T09:59:59.999999Z'), true, 20, 'stale'],
            'unauthorized' => [new DateTimeImmutable('2026-07-12T10:00:00Z'), false, 21, 'unauthorized'],
        ] as [$observedAt, $authorized, $gateOffset, $detail]) {
            $store = new CapturingShadowStore();
            $this->service(
                new RecordingAutomaticShadowSource([$this->candidate(
                    executorObservedAt: $observedAt,
                    executorAuthorized: $authorized,
                )]),
                $store,
                new DateTimeImmutable('2026-07-12T10:05:00Z'),
            )->execute($this->lease());

            $decision = $store->batches[0]->decisions[0];
            self::assertSame(DecisionOutcome::Blocked, $decision->outcome);
            self::assertSame($detail, $decision->gates[$gateOffset]->result->detailCode->value);
        }
    }

    public function testActiveRequestProducesClosedDeduplicatedExplainability(): void
    {
        $store = new CapturingShadowStore();
        $this->service(
            new RecordingAutomaticShadowSource([$this->candidate(activeRequestAbsent: false)]),
            $store,
            new DateTimeImmutable('2026-07-12T10:05:00Z'),
        )->execute($this->lease());

        $decision = $store->batches[0]->decisions[0];
        self::assertSame(DecisionOutcome::Deduplicated, $decision->outcome);
        self::assertSame('active_request_absent', $decision->gates[23]->result->code->value);
        self::assertSame('active_request_exists', $decision->gates[23]->result->detailCode->value);
    }

    public function testIncompatiblePolicyRetentionBlocksBeforePromotion(): void
    {
        $store = new CapturingShadowStore();
        $this->service(
            new RecordingAutomaticShadowSource([$this->candidate(policyRetentionCompatible: false)]),
            $store,
            new DateTimeImmutable('2026-07-12T10:05:00Z'),
        )->execute($this->lease());

        $decision = $store->batches[0]->decisions[0];
        self::assertSame(DecisionOutcome::Blocked, $decision->outcome);
        self::assertSame('policy_retention_compatible', $decision->gates[22]->result->code->value);
        self::assertSame('incompatible', $decision->gates[22]->result->detailCode->value);
    }

    public function testEligibleCandidateWithoutResolvedPolicyJsonFailsClosedBeforePersistence(): void
    {
        $store = new CapturingShadowStore();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Eligible automatic shadow promotion requires resolved policy JSON.');
        try {
            $this->service(
                new RecordingAutomaticShadowSource([$this->candidate(includeResolvedPolicyJson: false)]),
                $store,
                new DateTimeImmutable('2026-07-12T10:05:00Z'),
            )->execute($this->lease());
        } finally {
            self::assertSame([], $store->batches);
        }
    }

    public function testNodeAndTargetConcurrencyBlockIndependentlyInStableOrder(): void
    {
        foreach ([
            'node' => [false, true, 16, 17],
            'target' => [true, false, 17, 16],
        ] as [$nodeAvailable, $targetAvailable, $blockedOffset, $passedOffset]) {
            $store = new CapturingShadowStore();
            $this->service(
                new RecordingAutomaticShadowSource([$this->candidate(
                    nodeConcurrencyAvailable: $nodeAvailable,
                    targetConcurrencyAvailable: $targetAvailable,
                )]),
                $store,
                new DateTimeImmutable('2026-07-12T10:05:00Z'),
            )->execute($this->lease());

            $decision = $store->batches[0]->decisions[0];
            self::assertSame(DecisionOutcome::Blocked, $decision->outcome);
            self::assertSame('concurrency_limit_reached', $decision->gates[$blockedOffset]->result->detailCode->value);
            self::assertTrue($decision->gates[$passedOffset]->result->passed);
        }
    }

    public function testCounterResetIsAuditedAndCannotMakeBytesReasonDue(): void
    {
        $source = new RecordingAutomaticShadowSource([$this->candidate(
            lastSuccessAt: new DateTimeImmutable('2026-07-12T09:00:00Z'),
            maximumAgeSeconds: null,
            currentBytes: 100,
            baselineBytes: 200,
            bytesThreshold: 10,
            cooldownSeconds: 1,
        )]);
        $store = new CapturingShadowStore();
        $this->service($source, $store, new DateTimeImmutable('2026-07-12T10:05:00Z'))->execute($this->lease());
        self::assertCount(1, $source->resets);
        self::assertSame(DecisionOutcome::NotDue, $store->batches[0]->decisions[0]->outcome);
        self::assertNull($store->batches[0]->decisions[0]->reasonPriority);
    }

    public function testCompleteCounterEvidenceSelectsBytesReasonAndMissingPlacementStaysExplicit(): void
    {
        $source = new RecordingAutomaticShadowSource([$this->candidate(
            lastSuccessAt: new DateTimeImmutable('2026-07-12T09:00:00Z'),
            maximumAgeSeconds: null,
            currentBytes: 201,
            baselineBytes: 100,
            bytesThreshold: 100,
            cooldownSeconds: 1,
            placementPresent: false,
        )]);
        $store = new CapturingShadowStore();

        $this->service($source, $store, new DateTimeImmutable('2026-07-12T10:05:00Z'))->execute($this->lease());

        $decision = $store->batches[0]->decisions[0];
        self::assertSame('bytes_written', $decision->reasonPriority?->reason->value);
        self::assertNull($decision->placement);
        self::assertFalse($decision->gates[9]->result->passed);
        self::assertSame([], $source->resets);
    }

    public function testMaximumAgeBoundarySelectsTheAgeReason(): void
    {
        $source = new RecordingAutomaticShadowSource([$this->candidate(
            lastSuccessAt: new DateTimeImmutable('2026-07-12T09:00:00Z'),
            maximumAgeSeconds: 3600,
            currentBytes: null,
        )]);
        $store = new CapturingShadowStore();

        $this->service($source, $store, new DateTimeImmutable('2026-07-12T10:05:00Z'))->execute($this->lease());

        self::assertSame('max_age', $store->batches[0]->decisions[0]->reasonPriority?->reason->value);
    }

    public function testCompoundGuestAndCapacityGateBranchesRemainExplicit(): void
    {
        foreach ([
            ['guestActive' => false, 'guestTemplate' => false, 'availableBytes' => 1_000, 'minimumFreeBytes' => 100],
            ['guestActive' => true, 'guestTemplate' => null, 'availableBytes' => null, 'minimumFreeBytes' => 100],
            ['guestActive' => true, 'guestTemplate' => true, 'availableBytes' => 1_000, 'minimumFreeBytes' => null],
            ['guestActive' => true, 'guestTemplate' => false, 'availableBytes' => 50, 'minimumFreeBytes' => 100],
        ] as $case) {
            $source = new RecordingAutomaticShadowSource([$this->candidate(...$case)]);
            $store = new CapturingShadowStore();
            $this->service($source, $store, new DateTimeImmutable('2026-07-12T10:05:00Z'))->execute($this->lease());
            self::assertCount(24, $store->batches[0]->decisions[0]->gates);
        }
    }

    public function testStableIdHelperIsDirectlyCoveredOutsideNestedConstruction(): void
    {
        $service = $this->service(new RecordingAutomaticShadowSource([]), new CapturingShadowStore(), new DateTimeImmutable('2026-07-12T10:05:00Z'));
        $method = new \ReflectionMethod(RunAutomaticShadowEvaluation::class, 'stableId');

        $id = $method->invoke($service, 'run', 'bytes');
        self::assertIsString($id);
        self::assertSame(16, strlen($id));
    }

    public function testCommittedCycleReturnsBeforeSourceAndClockAreReadAgain(): void
    {
        $source = new RecordingAutomaticShadowSource([$this->candidate(activeRequestAbsent: false)]);
        $store = new CapturingShadowStore();
        $store->committed = ShadowEvaluationPersistenceResult::AlreadyPersisted;

        self::assertSame(
            ShadowEvaluationPersistenceResult::AlreadyPersisted,
            $this->service($source, $store, new DateTimeImmutable('2026-07-12T10:06:00Z'))->execute($this->lease()),
        );
        self::assertSame(0, $source->candidateReads);
        self::assertSame(1, $store->committedChecks);
        self::assertSame([], $store->batches);
    }

    public function testActiveRequestRaceIsReevaluatedExactlyOnceThenFailsClosed(): void
    {
        $source = new RecordingAutomaticShadowSource([$this->candidate()]);
        $store = new CapturingShadowStore();
        $store->persistConflicts = 2;

        try {
            $this->service($source, $store, new DateTimeImmutable('2026-07-12T10:05:00Z'))->execute($this->lease());
            self::fail('A second source/persist race must fail closed.');
        } catch (ShadowEvaluationConflict $conflict) {
            self::assertSame(ShadowEvaluationConflictCode::ActiveRequestChanged, $conflict->failureCode);
        }
        self::assertSame(2, $source->candidateReads);
        self::assertCount(2, $store->batches);
    }

    public function testUnrelatedPersistenceConflictIsNeverRetried(): void
    {
        $source = new RecordingAutomaticShadowSource([$this->candidate()]);
        $store = new CapturingShadowStore();
        $store->persistConflicts = 1;
        $store->persistConflictCode = ShadowEvaluationConflictCode::PayloadMismatch;

        try {
            $this->service($source, $store, new DateTimeImmutable('2026-07-12T10:05:00Z'))->execute($this->lease());
            self::fail('A payload conflict must fail closed without re-evaluation.');
        } catch (ShadowEvaluationConflict $conflict) {
            self::assertSame(ShadowEvaluationConflictCode::PayloadMismatch, $conflict->failureCode);
        }
        self::assertSame(1, $source->candidateReads);
        self::assertCount(1, $store->batches);
    }

    private function service(RecordingAutomaticShadowSource $source, CapturingShadowStore $store, DateTimeImmutable $now): RunAutomaticShadowEvaluation
    {
        return new RunAutomaticShadowEvaluation($source, new PersistShadowEvaluation($store), new EligibilityEvaluator(), new EvidenceFreshnessPolicy(), new ReasonSelector(), new PriorityResolver(), new FixedShadowClock($now));
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(new CollectorWorkerId(str_repeat('w', 16)), new CollectorCycleToken(str_repeat('c', 16)), 7, new DateTimeImmutable('2026-07-12T10:10:00Z'));
    }

    private function candidate(
        ?DateTimeImmutable $executorObservedAt = new DateTimeImmutable('2026-07-12T10:00:00Z'),
        bool $executorAuthorized = true,
        ?DateTimeImmutable $lastSuccessAt = null,
        ?int $maximumAgeSeconds = 3600,
        ?int $currentBytes = 100,
        ?int $baselineBytes = null,
        ?int $bytesThreshold = null,
        ?int $cooldownSeconds = null,
        bool $placementPresent = true,
        bool $guestActive = true,
        ?bool $guestTemplate = false,
        ?int $availableBytes = 1_000,
        ?int $minimumFreeBytes = 100,
        bool $activeRequestAbsent = true,
        bool $nodeConcurrencyAvailable = true,
        bool $targetConcurrencyAvailable = true,
        bool $policyRetentionCompatible = true,
        ?string $policyId = null,
        int $policyPriority = 0,
        bool $includeResolvedPolicyJson = true,
    ): AutomaticShadowCandidate {
        $id = static fn (string $value): string => substr(hash('sha256', $value, true), 0, 16);
        $at = new DateTimeImmutable('2026-07-12T10:00:00Z');
        $json = '{"mode":"snapshot"}';
        return new AutomaticShadowCandidate(
            $id('connection'), true, $id('cluster'), true, $id('guest'), $guestActive, $guestTemplate, $at,
            $placementPresent ? $id('node') : null, true, $placementPresent ? 2 : null, $placementPresent ? $at : null, $policyId ?? $id('policy'), 3, true, $policyRetentionCompatible, $policyRetentionCompatible ? hash('sha256', $json, true) : hash('sha256', 'policy', true),
            true, false, $id('target'), 4, true, true, true, true, $at,
            null === $availableBytes ? null : new UInt64Decimal((string) $availableBytes),
            null === $minimumFreeBytes ? null : new UInt64Decimal((string) $minimumFreeBytes),
            $nodeConcurrencyAvailable, $targetConcurrencyAvailable,
            true, $at, $executorObservedAt, $executorAuthorized, $activeRequestAbsent, $lastSuccessAt, $maximumAgeSeconds,
            null === $currentBytes ? null : new UInt64Decimal((string) $currentBytes), $at,
            null === $baselineBytes ? null : new UInt64Decimal((string) $baselineBytes),
            null === $bytesThreshold ? null : new UInt64Decimal((string) $bytesThreshold), $cooldownSeconds,
            $policyRetentionCompatible && $includeResolvedPolicyJson ? $json : null,
            $policyPriority,
        );
    }
}

final class RecordingAutomaticShadowSource implements AutomaticShadowEvaluationSource
{
    /** @var list<array{AutomaticShadowCandidate, DateTimeImmutable}> */ public array $resets = [];
    public int $candidateReads = 0;
    /** @param list<AutomaticShadowCandidate> $items */ public function __construct(private array $items) {}
    public function candidates(CollectorLease $lease): array { ++$this->candidateReads; return $this->items; }
    public function recordCounterReset(CollectorLease $lease, AutomaticShadowCandidate $candidate, DateTimeImmutable $detectedAt): void { $this->resets[] = [$candidate, $detectedAt]; }
}

final class CapturingShadowStore implements ShadowEvaluationStore
{
    /** @var list<ShadowEvaluationBatch> */ public array $batches = [];
    public ?ShadowEvaluationPersistenceResult $committed = null;
    public int $committedChecks = 0;
    public int $persistConflicts = 0;
    public ShadowEvaluationConflictCode $persistConflictCode = ShadowEvaluationConflictCode::ActiveRequestChanged;
    public function committedResult(CollectorLease $lease, ShadowEvaluationRunId $runId, int $evaluatorVersion): ?ShadowEvaluationPersistenceResult { ++$this->committedChecks; return $this->committed; }
    public function persist(CollectorLease $lease, ShadowEvaluationBatch $batch): ShadowEvaluationPersistenceResult { $this->batches[] = $batch; if ($this->persistConflicts > 0) { --$this->persistConflicts; throw new ShadowEvaluationConflict($this->persistConflictCode); } return ShadowEvaluationPersistenceResult::Persisted; }
}

final readonly class FixedShadowClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}
