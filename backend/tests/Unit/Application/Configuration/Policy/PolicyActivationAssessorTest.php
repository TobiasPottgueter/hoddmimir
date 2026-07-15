<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Configuration\Policy;

use App\Application\Configuration\Policy\PolicyActivationAssessor;
use App\Application\Configuration\Policy\PolicyActivationBlockerCode;
use App\Application\Configuration\Policy\PolicyActivationEvidence;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\BackupPolicy;
use App\Domain\Policy\Compression;
use App\Domain\Policy\PolicyId;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyThresholds;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use App\Domain\Target\ActivationEvidenceObservation;
use App\Domain\Target\BackupTargetId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyActivationAssessorTest extends TestCase
{
    private const string NOW = '2026-07-12T12:00:00.000000Z';

    public function testFreshCompleteEvidenceAtExactFiveMinuteBoundaryCanEnable(): void
    {
        $fresh = $this->observation(true, '2026-07-12T11:55:00.000000Z');
        $assessment = (new PolicyActivationAssessor())->assess(
            $this->completePolicy(),
            new PolicyActivationEvidence(9, $fresh->observedAt, $fresh, $fresh),
            new DateTimeImmutable(self::NOW),
        );

        self::assertTrue($assessment->canEnable());
        self::assertSame([], $assessment->blockers);
    }

    /** @param list<PolicyActivationBlockerCode> $expected */
    #[DataProvider('evidenceFailureProvider')]
    public function testEveryEvidenceFailureIsClassified(
        ?int $pveMajor,
        ?string $pveObservedAt,
        ActivationEvidenceObservation $target,
        ActivationEvidenceObservation $executor,
        array $expected,
    ): void {
        $assessment = (new PolicyActivationAssessor())->assess(
            $this->completePolicy(),
            new PolicyActivationEvidence(
                $pveMajor,
                null === $pveObservedAt ? null : new DateTimeImmutable($pveObservedAt),
                $target,
                $executor,
            ),
            new DateTimeImmutable(self::NOW),
        );
        self::assertFalse($assessment->canEnable());
        self::assertSame($expected, $assessment->blockers);
    }

    /** @return iterable<string, array{?int, ?string, ActivationEvidenceObservation, ActivationEvidenceObservation, list<PolicyActivationBlockerCode>}> */
    public static function evidenceFailureProvider(): iterable
    {
        $fresh = new ActivationEvidenceObservation(true, new DateTimeImmutable(self::NOW));
        yield 'all missing' => [null, null, new ActivationEvidenceObservation(null, null), new ActivationEvidenceObservation(null, null), [
            PolicyActivationBlockerCode::PveEvidenceMissing,
            PolicyActivationBlockerCode::TargetEvidenceMissing,
            PolicyActivationBlockerCode::ExecutorEvidenceMissing,
        ]];
        yield 'pve timestamp missing' => [9, null, $fresh, $fresh, [PolicyActivationBlockerCode::PveEvidenceMissing]];
        yield 'pve major missing' => [null, self::NOW, $fresh, $fresh, [PolicyActivationBlockerCode::PveEvidenceMissing]];
        yield 'pve stale' => [9, '2026-07-12T11:54:59.999999Z', $fresh, $fresh, [PolicyActivationBlockerCode::PveEvidenceStale]];
        yield 'pve future' => [9, '2026-07-12T12:00:00.000001Z', $fresh, $fresh, [PolicyActivationBlockerCode::PveEvidenceFuture]];
        yield 'unsupported is unique' => [6, self::NOW, $fresh, $fresh, [PolicyActivationBlockerCode::UnsupportedPveMajor]];
        yield 'target stale' => [9, self::NOW, new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:54:59Z')), $fresh, [PolicyActivationBlockerCode::TargetEvidenceStale]];
        yield 'target future' => [9, self::NOW, new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T12:00:01Z')), $fresh, [PolicyActivationBlockerCode::TargetEvidenceFuture]];
        yield 'target rejected' => [9, self::NOW, new ActivationEvidenceObservation(false, new DateTimeImmutable(self::NOW)), $fresh, [PolicyActivationBlockerCode::TargetDisabled]];
        yield 'executor stale' => [9, self::NOW, $fresh, new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T11:54:59Z')), [PolicyActivationBlockerCode::ExecutorEvidenceStale]];
        yield 'executor future' => [9, self::NOW, $fresh, new ActivationEvidenceObservation(true, new DateTimeImmutable('2026-07-12T12:00:01Z')), [PolicyActivationBlockerCode::ExecutorEvidenceFuture]];
        yield 'executor rejected' => [9, self::NOW, $fresh, new ActivationEvidenceObservation(false, new DateTimeImmutable(self::NOW)), [PolicyActivationBlockerCode::ExecutorUnauthorized]];
    }

    public function testConfigurationRetentionAndPbsRulesUseOneOrderedDeduplicatedAssessment(): void
    {
        $fresh = $this->observation(true, self::NOW);
        $incomplete = BackupPolicy::draft(
            new PolicyId(str_repeat('p', 16)), new PolicyRevision(1), null, null, null,
            null, null, null, null,
        );
        $assessment = (new PolicyActivationAssessor())->assess(
            $incomplete,
            new PolicyActivationEvidence(9, $fresh->observedAt, $fresh, $fresh, true, true),
            new DateTimeImmutable(self::NOW),
        );
        self::assertSame([
            PolicyActivationBlockerCode::RetentionExecutionForbiddenForPbsTarget,
            PolicyActivationBlockerCode::TargetUnconfigured,
            PolicyActivationBlockerCode::ModeUnconfigured,
            PolicyActivationBlockerCode::CompressionUnconfigured,
            PolicyActivationBlockerCode::RetentionUnconfigured,
            PolicyActivationBlockerCode::PriorityUnconfigured,
            PolicyActivationBlockerCode::ThresholdsUnconfigured,
            PolicyActivationBlockerCode::ScheduleUnconfigured,
        ], $assessment->blockers);

        $legacy = BackupPolicy::draft(
            new PolicyId(str_repeat('l', 16)), new PolicyRevision(1), new BackupTargetId(str_repeat('t', 16)),
            BackupMode::Snapshot, Compression::Zstd, RetentionPolicy::legacyMaxFiles(2),
            new PolicyPriority(1), new PolicyThresholds(60, null, null), Schedule::CollectorCycle,
        );
        self::assertSame(
            [PolicyActivationBlockerCode::RetentionIncompatible],
            (new PolicyActivationAssessor())->assess(
                $legacy,
                new PolicyActivationEvidence(9, $fresh->observedAt, $fresh, $fresh),
                new DateTimeImmutable(self::NOW),
            )->blockers,
        );
    }

    public function testMissingPveAndTargetEvidenceStillReportEveryIndependentConfigurationProblem(): void
    {
        $missing = new ActivationEvidenceObservation(null, null);
        $incomplete = BackupPolicy::draft(
            new PolicyId(str_repeat('i', 16)), new PolicyRevision(1), null, null, null,
            null, null, null, null,
        );

        $assessment = (new PolicyActivationAssessor())->assess(
            $incomplete,
            new PolicyActivationEvidence(null, null, $missing, $missing, false, false, false),
            new DateTimeImmutable(self::NOW),
        );

        self::assertSame([
            PolicyActivationBlockerCode::PveEvidenceMissing,
            PolicyActivationBlockerCode::TargetDisabled,
            PolicyActivationBlockerCode::TargetEvidenceMissing,
            PolicyActivationBlockerCode::ExecutorEvidenceMissing,
            PolicyActivationBlockerCode::TargetUnconfigured,
            PolicyActivationBlockerCode::ModeUnconfigured,
            PolicyActivationBlockerCode::CompressionUnconfigured,
            PolicyActivationBlockerCode::RetentionUnconfigured,
            PolicyActivationBlockerCode::PriorityUnconfigured,
            PolicyActivationBlockerCode::ThresholdsUnconfigured,
            PolicyActivationBlockerCode::ScheduleUnconfigured,
        ], $assessment->blockers);
    }

    private function completePolicy(): BackupPolicy
    {
        return BackupPolicy::draft(
            new PolicyId(str_repeat('p', 16)), new PolicyRevision(1), new BackupTargetId(str_repeat('t', 16)),
            BackupMode::Snapshot, Compression::Zstd,
            RetentionPolicy::prune(null, 2, null, null, null, null, null),
            new PolicyPriority(1), new PolicyThresholds(60, null, null), Schedule::CollectorCycle,
        );
    }

    private function observation(?bool $accepted, ?string $observedAt): ActivationEvidenceObservation
    {
        return new ActivationEvidenceObservation(
            $accepted,
            null === $observedAt ? null : new DateTimeImmutable($observedAt),
        );
    }
}
