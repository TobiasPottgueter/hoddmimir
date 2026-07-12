<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Scheduler\Shadow\OrderedShadowGate;
use App\Application\Scheduler\Shadow\ShadowDecision;
use App\Application\Scheduler\Shadow\ShadowDecisionId;
use App\Application\Scheduler\Shadow\ShadowEvaluationBatch;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflict;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflictCode;
use App\Application\Scheduler\Shadow\ShadowEvaluationRunId;
use App\Application\Scheduler\Shadow\ShadowPlacementEvidence;
use App\Application\Scheduler\Shadow\ShadowPolicyEvidence;
use App\Application\Scheduler\Shadow\ShadowTargetEvidence;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateResult;
use App\Domain\Scheduler\GateScope;
use App\Domain\Scheduler\GateSubjectId;
use App\Domain\Scheduler\Priority;
use App\Domain\Scheduler\ReasonPriority;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShadowEvaluationModelTest extends TestCase
{
    public function testIdentifiersExposeStableBinaryHexAndEquality(): void
    {
        $run = new ShadowEvaluationRunId(str_repeat("\x01", 16));
        $decision = new ShadowDecisionId(str_repeat("\x02", 16));

        self::assertSame(str_repeat("\x01", 16), $run->binary());
        self::assertSame(str_repeat('01', 16), $run->toHex());
        self::assertTrue($run->equals(new ShadowEvaluationRunId(str_repeat("\x01", 16))));
        self::assertFalse($run->equals(new ShadowEvaluationRunId(str_repeat("\x03", 16))));
        self::assertSame(str_repeat("\x02", 16), $decision->binary());
        self::assertSame(str_repeat('02', 16), $decision->toHex());
        self::assertTrue($decision->equals(new ShadowDecisionId(str_repeat("\x02", 16))));
        self::assertFalse($decision->equals(new ShadowDecisionId(str_repeat("\x03", 16))));
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function testIdentifiersRejectInvalidLengths(string $class, string $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new $class($bytes);
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'short run' => [ShadowEvaluationRunId::class, str_repeat('a', 15)];
        yield 'long run' => [ShadowEvaluationRunId::class, str_repeat('a', 17)];
        yield 'short decision' => [ShadowDecisionId::class, str_repeat('a', 15)];
        yield 'long decision' => [ShadowDecisionId::class, str_repeat('a', 17)];
    }

    public function testEvidenceNormalizesUtcAndExposesHash(): void
    {
        $placement = new ShadowPlacementEvidence($this->inventoryId(1), 7, new DateTimeImmutable('2026-07-12T14:00:00+02:00'));
        $policy = new ShadowPolicyEvidence($this->inventoryId(2), 8, str_repeat("\xaa", 32));
        $target = new ShadowTargetEvidence($this->inventoryId(3), 9);

        self::assertSame('2026-07-12T12:00:00+00:00', $placement->observedAt->format('c'));
        self::assertSame(7, $placement->revision);
        self::assertSame(str_repeat("\xaa", 32), $policy->snapshotHash());
        self::assertSame(str_repeat('aa', 32), $policy->snapshotHashHex());
        self::assertSame(8, $policy->revision);
        self::assertSame(9, $target->revision);
    }

    #[DataProvider('invalidEvidenceProvider')]
    public function testEvidenceRejectsInvalidValues(string $kind, int $revision, int $hashLength): void
    {
        $this->expectException(InvalidArgumentException::class);
        match ($kind) {
            'placement' => new ShadowPlacementEvidence($this->inventoryId(1), $revision, $this->time()),
            'policy' => new ShadowPolicyEvidence($this->inventoryId(1), $revision, str_repeat('h', $hashLength)),
            'target' => new ShadowTargetEvidence($this->inventoryId(1), $revision),
            default => throw new \LogicException('Unknown evidence test case.'),
        };
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function invalidEvidenceProvider(): iterable
    {
        yield 'placement revision' => ['placement', 0, 32];
        yield 'policy revision' => ['policy', 0, 32];
        yield 'policy short hash' => ['policy', 1, 31];
        yield 'policy long hash' => ['policy', 1, 33];
        yield 'target revision' => ['target', 0, 32];
    }

    public function testOrderedGateRequiresPositivePosition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OrderedShadowGate(0, $this->gate(true));
    }

    public function testDecisionNormalizesEvidenceTimesAndPreservesOrderedGates(): void
    {
        $decision = $this->eligibleDecision(1);

        self::assertSame('2026-07-12T12:00:00+00:00', $decision->inventoryObservedAt->format('c'));
        self::assertSame('2026-07-12T12:01:00+00:00', $decision->capacityObservedAt?->format('c'));
        self::assertSame('2026-07-12T12:02:00+00:00', $decision->writeStateObservedAt?->format('c'));
        self::assertSame([1, 2], array_column($decision->gates, 'position'));
    }

    public function testBlockedDecisionMayPersistMissingPlacementPolicyAndTarget(): void
    {
        $decision = $this->decision(
            DecisionOutcome::Blocked,
            null,
            null,
            null,
            null,
            [new OrderedShadowGate(1, $this->gate(false))],
        );

        self::assertNull($decision->placement);
        self::assertNull($decision->policy);
        self::assertNull($decision->target);
    }

    public function testDeduplicatedDecisionAcceptsAFailedGate(): void
    {
        $decision = $this->decision(
            DecisionOutcome::Deduplicated,
            new ReasonPriority(BackupReason::NeverBackedUp, Priority::NeverBackedUp),
            $this->placement(),
            $this->policy(),
            $this->target(),
            [new OrderedShadowGate(1, $this->gate(false))],
        );

        self::assertSame(DecisionOutcome::Deduplicated, $decision->outcome);
    }

    public function testNotDueDecisionAcceptsPassedGatesWithPlacementAndNoReason(): void
    {
        $decision = $this->decision(
            DecisionOutcome::NotDue,
            null,
            $this->placement(),
            $this->policy(),
            $this->target(),
            [new OrderedShadowGate(1, $this->gate(true))],
        );

        self::assertNull($decision->reasonPriority);
    }

    #[DataProvider('invalidDecisionProvider')]
    public function testDecisionRejectsInvalidOutcomeOrGateShape(string $case): void
    {
        $this->expectException(InvalidArgumentException::class);
        match ($case) {
            'empty gates' => $this->decision(DecisionOutcome::Blocked, null, null, null, null, []),
            'invalid gate value' => $this->decision(DecisionOutcome::Blocked, null, null, null, null, [new \stdClass()]),
            'gate starts at two' => $this->decision(DecisionOutcome::Blocked, null, null, null, null, [new OrderedShadowGate(2, $this->gate(false))]),
            'gate gap' => $this->decision(DecisionOutcome::Blocked, null, null, null, null, [
                new OrderedShadowGate(1, $this->gate(true)),
                new OrderedShadowGate(3, $this->gate(false)),
            ]),
            'eligible failed' => $this->decision(DecisionOutcome::Eligible, $this->reason(), $this->placement(), $this->policy(), $this->target(), [new OrderedShadowGate(1, $this->gate(false))]),
            'eligible no reason' => $this->decision(DecisionOutcome::Eligible, null, $this->placement(), $this->policy(), $this->target(), [new OrderedShadowGate(1, $this->gate(true))]),
            'eligible no placement' => $this->decision(DecisionOutcome::Eligible, $this->reason(), null, $this->policy(), $this->target(), [new OrderedShadowGate(1, $this->gate(true))]),
            'eligible no policy' => $this->decision(DecisionOutcome::Eligible, $this->reason(), $this->placement(), null, $this->target(), [new OrderedShadowGate(1, $this->gate(true))]),
            'eligible no target' => $this->decision(DecisionOutcome::Eligible, $this->reason(), $this->placement(), $this->policy(), null, [new OrderedShadowGate(1, $this->gate(true))]),
            'not due failed' => $this->decision(DecisionOutcome::NotDue, null, $this->placement(), $this->policy(), $this->target(), [new OrderedShadowGate(1, $this->gate(false))]),
            'not due reason' => $this->decision(DecisionOutcome::NotDue, $this->reason(), $this->placement(), $this->policy(), $this->target(), [new OrderedShadowGate(1, $this->gate(true))]),
            'not due no placement' => $this->decision(DecisionOutcome::NotDue, null, null, $this->policy(), $this->target(), [new OrderedShadowGate(1, $this->gate(true))]),
            'not due no policy' => $this->decision(DecisionOutcome::NotDue, null, $this->placement(), null, $this->target(), [new OrderedShadowGate(1, $this->gate(true))]),
            'not due no target' => $this->decision(DecisionOutcome::NotDue, null, $this->placement(), $this->policy(), null, [new OrderedShadowGate(1, $this->gate(true))]),
            'blocked passed' => $this->decision(DecisionOutcome::Blocked, null, null, null, null, [new OrderedShadowGate(1, $this->gate(true))]),
            'deduplicated passed' => $this->decision(DecisionOutcome::Deduplicated, null, null, null, null, [new OrderedShadowGate(1, $this->gate(true))]),
            default => throw new \LogicException('Unknown decision test case.'),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDecisionProvider(): iterable
    {
        foreach ([
            'empty gates', 'invalid gate value', 'gate starts at two', 'gate gap',
            'eligible failed', 'eligible no reason', 'eligible no placement', 'eligible no policy', 'eligible no target',
            'not due failed', 'not due reason', 'not due no placement', 'not due no policy', 'not due no target',
            'blocked passed', 'deduplicated passed',
        ] as $case) {
            yield $case => [$case];
        }
    }

    public function testBatchNormalizesTimesSortsDecisionsAndHashesCanonicalContent(): void
    {
        $first = $this->eligibleDecision(1);
        $second = $this->eligibleDecision(2);
        $batch = $this->batch([$second, $first]);
        $same = $this->batch([$first, $second]);

        self::assertSame([$first->id, $second->id], array_column($batch->decisions, 'id'));
        self::assertSame('2026-07-12T12:00:00+00:00', $batch->startedAt->format('c'));
        self::assertSame('2026-07-12T12:05:00+00:00', $batch->finishedAt->format('c'));
        self::assertSame(4, $batch->gateCount());
        self::assertSame(32, strlen($batch->contentHash()));
        self::assertSame($batch->contentHash(), $same->contentHash());
        self::assertNotSame($batch->contentHash(), $this->batch([$first, $second], evaluatorVersion: 2)->contentHash());
    }

    public function testBatchAllowsAnEmptyEvaluation(): void
    {
        $batch = $this->batch([]);

        self::assertSame([], $batch->decisions);
        self::assertSame(0, $batch->gateCount());
    }

    public function testBatchHashCoversAbsentOptionalEvidenceAndUnobservedGates(): void
    {
        $failed = new GateResult(
            GateCode::PlacementPresent,
            false,
            GateScope::Placement,
            new GateSubjectId(str_repeat("\x0a", 16)),
            null,
            GateDetailCode::Missing,
        );
        $decision = new ShadowDecision(
            new ShadowDecisionId(str_repeat("\x05", 16)),
            $this->inventoryId(10),
            $this->inventoryId(11),
            $this->inventoryId(12),
            null,
            DecisionOutcome::Blocked,
            null,
            null,
            null,
            $this->time(),
            null,
            null,
            [new OrderedShadowGate(1, $failed), new OrderedShadowGate(2, $failed)],
        );
        $batch = $this->batch([$decision]);

        self::assertSame(2, $batch->gateCount());
        self::assertSame(32, strlen($batch->contentHash()));
    }

    #[DataProvider('invalidBatchProvider')]
    public function testBatchRejectsInvalidHeaderAndDecisionLists(string $case): void
    {
        $this->expectException(InvalidArgumentException::class);
        match ($case) {
            'version' => new ShadowEvaluationBatch($this->runId(), $this->cycleToken(), 0, $this->time(), $this->time(), []),
            'version too high' => new ShadowEvaluationBatch($this->runId(), $this->cycleToken(), 65536, $this->time(), $this->time(), []),
            'time' => new ShadowEvaluationBatch($this->runId(), $this->cycleToken(), 1, $this->time('+1 minute'), $this->time(), []),
            'invalid decision' => $this->batch([new \stdClass()]),
            'duplicate decision' => $this->batch([$this->eligibleDecision(1), $this->eligibleDecision(1)]),
            default => throw new \LogicException('Unknown batch test case.'),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBatchProvider(): iterable
    {
        yield 'version' => ['version'];
        yield 'version too high' => ['version too high'];
        yield 'time' => ['time'];
        yield 'invalid decision' => ['invalid decision'];
        yield 'duplicate decision' => ['duplicate decision'];
    }

    public function testBatchAcceptsMaximumUnsignedSmallIntegerEvaluatorVersion(): void
    {
        $batch = new ShadowEvaluationBatch(
            $this->runId(),
            $this->cycleToken(),
            65535,
            $this->time(),
            $this->time(),
            [],
        );

        self::assertSame(65535, $batch->evaluatorVersion);
    }

    public function testTypedConflictPreservesCodeMessageAndPreviousFailure(): void
    {
        $previous = new \RuntimeException('database');
        $conflict = new ShadowEvaluationConflict(ShadowEvaluationConflictCode::PayloadMismatch, 'mismatch', $previous);

        self::assertSame(ShadowEvaluationConflictCode::PayloadMismatch, $conflict->failureCode);
        self::assertSame('mismatch', $conflict->getMessage());
        self::assertSame($previous, $conflict->getPrevious());
        self::assertSame('The shadow evaluation conflicts with persisted state.', (new ShadowEvaluationConflict(ShadowEvaluationConflictCode::RunIdReused))->getMessage());
    }

    /** @param array<int, mixed> $gates */
    private function decision(
        DecisionOutcome $outcome,
        ?ReasonPriority $reason,
        ?ShadowPlacementEvidence $placement,
        ?ShadowPolicyEvidence $policy,
        ?ShadowTargetEvidence $target,
        array $gates,
    ): ShadowDecision {
        return new ShadowDecision(
            new ShadowDecisionId(str_repeat("\x01", 16)),
            $this->inventoryId(10),
            $this->inventoryId(11),
            $this->inventoryId(12),
            $placement,
            $outcome,
            $reason,
            $policy,
            $target,
            new DateTimeImmutable('2026-07-12T14:00:00+02:00'),
            new DateTimeImmutable('2026-07-12T14:01:00+02:00'),
            new DateTimeImmutable('2026-07-12T14:02:00+02:00'),
            // @phpstan-ignore-next-line argument.type (one test intentionally crosses the PHPDoc runtime boundary)
            $gates,
        );
    }

    private function eligibleDecision(int $idByte): ShadowDecision
    {
        return new ShadowDecision(
            new ShadowDecisionId(str_repeat(pack('C', $idByte), 16)),
            $this->inventoryId(10),
            $this->inventoryId(11),
            $this->inventoryId(12),
            $this->placement(),
            DecisionOutcome::Eligible,
            $this->reason(),
            $this->policy(),
            $this->target(),
            new DateTimeImmutable('2026-07-12T14:00:00+02:00'),
            new DateTimeImmutable('2026-07-12T14:01:00+02:00'),
            new DateTimeImmutable('2026-07-12T14:02:00+02:00'),
            [new OrderedShadowGate(1, $this->gate(true)), new OrderedShadowGate(2, $this->gate(true))],
        );
    }

    /** @param array<int, mixed> $decisions */
    private function batch(array $decisions, int $evaluatorVersion = 1): ShadowEvaluationBatch
    {
        return new ShadowEvaluationBatch(
            $this->runId(),
            $this->cycleToken(),
            $evaluatorVersion,
            new DateTimeImmutable('2026-07-12T14:00:00+02:00'),
            new DateTimeImmutable('2026-07-12T14:05:00+02:00'),
            // @phpstan-ignore-next-line argument.type (one test intentionally crosses the PHPDoc runtime boundary)
            $decisions,
        );
    }

    private function placement(): ShadowPlacementEvidence
    {
        return new ShadowPlacementEvidence($this->inventoryId(13), 3, $this->time());
    }

    private function policy(): ShadowPolicyEvidence
    {
        return new ShadowPolicyEvidence($this->inventoryId(14), 4, str_repeat("\x0e", 32));
    }

    private function target(): ShadowTargetEvidence
    {
        return new ShadowTargetEvidence($this->inventoryId(15), 5);
    }

    private function reason(): ReasonPriority
    {
        return new ReasonPriority(BackupReason::NeverBackedUp, Priority::NeverBackedUp);
    }

    private function gate(bool $passed): GateResult
    {
        return new GateResult(
            GateCode::ConnectionEnabled,
            $passed,
            GateScope::Connection,
            new GateSubjectId(str_repeat("\x0a", 16)),
            $this->time(),
            $passed ? GateDetailCode::Passed : GateDetailCode::Disabled,
        );
    }

    private function inventoryId(int $byte): InventoryIdentifier
    {
        return new InventoryIdentifier(str_repeat(pack('C', $byte), 16));
    }

    private function runId(): ShadowEvaluationRunId
    {
        return new ShadowEvaluationRunId(str_repeat("\x20", 16));
    }

    private function cycleToken(): CollectorCycleToken
    {
        return new CollectorCycleToken(str_repeat("\x21", 16));
    }

    private function time(string $modifier = ''): DateTimeImmutable
    {
        $time = new DateTimeImmutable('2026-07-12T12:00:00Z');
        return '' === $modifier ? $time : $time->modify($modifier);
    }
}
