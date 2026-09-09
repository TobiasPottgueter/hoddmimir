<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Notification;

use App\Application\Backup\Notification\PrePostProblemClassifier;
use App\Application\Scheduler\Shadow\OrderedShadowGate;
use App\Domain\Backup\BackupProblemCode;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateResult;
use App\Domain\Scheduler\GateScope;
use App\Domain\Scheduler\GateSubjectId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrePostProblemClassifierTest extends TestCase
{
    #[DataProvider('schedulerProblems')]
    public function testDueSchedulerCandidateMapsRealProblemGate(
        GateCode $gate,
        GateDetailCode $detail,
        BackupProblemCode $expected,
    ): void {
        $problem = (new PrePostProblemClassifier())->scheduler([$this->failed($gate, $detail)], true);

        self::assertNotNull($problem);
        self::assertSame($expected, $problem->code);
        self::assertSame($gate->value.'.'.$detail->value, $problem->detailCode);
    }

    /** @return iterable<string, array{GateCode, GateDetailCode, BackupProblemCode}> */
    public static function schedulerProblems(): iterable
    {
        yield 'capacity' => [GateCode::MinimumFreeSpace, GateDetailCode::InsufficientFreeSpace, BackupProblemCode::CapacityBlocked];
        yield 'missing capacity evidence' => [GateCode::MinimumFreeSpace, GateDetailCode::Missing, BackupProblemCode::EvidenceStale];
        yield 'permission' => [GateCode::ExecutorAuthorized, GateDetailCode::Unauthorized, BackupProblemCode::PermissionBlocked];
        yield 'stale evidence' => [GateCode::CapacityFresh, GateDetailCode::Stale, BackupProblemCode::EvidenceStale];
        yield 'placement' => [GateCode::TargetNodeAllowed, GateDetailCode::NotAllowed, BackupProblemCode::PlacementChanged];
        yield 'configuration' => [GateCode::PbsMappingValid, GateDetailCode::InvalidMapping, BackupProblemCode::ConfigurationBlocked];
    }

    #[DataProvider('nonProblems')]
    public function testNormalPlanningAndBackpressureStatesDoNotAlarm(GateCode $gate, GateDetailCode $detail): void
    {
        self::assertNull((new PrePostProblemClassifier())->scheduler([$this->failed($gate, $detail)], true));
    }

    /** @return iterable<string, array{GateCode, GateDetailCode}> */
    public static function nonProblems(): iterable
    {
        yield 'disabled policy' => [GateCode::PolicyEnabled, GateDetailCode::Disabled];
        yield 'excluded guest' => [GateCode::ExplicitExclusionAbsent, GateDetailCode::ExplicitlyExcluded];
        yield 'node concurrency' => [GateCode::NodeConcurrency, GateDetailCode::ConcurrencyLimitReached];
        yield 'target concurrency' => [GateCode::TargetConcurrency, GateDetailCode::ConcurrencyLimitReached];
        yield 'active request' => [GateCode::ActiveRequestAbsent, GateDetailCode::ActiveRequestExists];
    }

    public function testNotDueCandidateNeverAlarms(): void
    {
        self::assertNull((new PrePostProblemClassifier())->scheduler([
            $this->failed(GateCode::MinimumFreeSpace, GateDetailCode::InsufficientFreeSpace),
        ], false));
    }

    #[DataProvider('backpressureGates')]
    public function testBackpressureSuppressesIncidentalRealGateFailure(GateCode $backpressure, GateDetailCode $detail): void
    {
        self::assertNull((new PrePostProblemClassifier())->scheduler([
            $this->failed(GateCode::CapacityFresh, GateDetailCode::Stale, 1),
            $this->failed($backpressure, $detail, 2),
        ], true));
    }

    /** @return iterable<string, array{GateCode, GateDetailCode}> */
    public static function backpressureGates(): iterable
    {
        yield 'active request' => [GateCode::ActiveRequestAbsent, GateDetailCode::ActiveRequestExists];
        yield 'node slot' => [GateCode::NodeConcurrency, GateDetailCode::ConcurrencyLimitReached];
        yield 'target slot' => [GateCode::TargetConcurrency, GateDetailCode::ConcurrencyLimitReached];
    }

    #[DataProvider('queueProblems')]
    public function testQueueAndPreSubmitCodesAreClosedAndIntentional(string $detail, ?BackupProblemCode $expected): void
    {
        self::assertSame($expected, (new PrePostProblemClassifier())->queue($detail)?->code);
    }

    /** @return iterable<string, array{string, ?BackupProblemCode}> */
    public static function queueProblems(): iterable
    {
        yield 'capacity' => ['capacity_unavailable', BackupProblemCode::CapacityBlocked];
        yield 'permission' => ['executor_unauthorized', BackupProblemCode::PermissionBlocked];
        yield 'stale' => ['executor_seen_stale', BackupProblemCode::EvidenceStale];
        yield 'expected size evidence' => ['expected_size_missing', BackupProblemCode::EvidenceStale];
        yield 'placement' => ['snapshot_revision_changed', BackupProblemCode::PlacementChanged];
        yield 'configuration' => ['pbs_evidence_invalid', BackupProblemCode::ConfigurationBlocked];
        yield 'execution disabled' => ['execution_disabled', null];
        yield 'concurrency' => ['node_slot_unavailable', null];
        yield 'active request' => ['active_request_exists', null];
        yield 'cancel' => ['cancel_requested', null];
    }

    public function testUnknownQueueCodeRequiresAnExplicitMappingDecision(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PrePostProblemClassifier())->queue('future_unknown_code');
    }

    private function failed(GateCode $code, GateDetailCode $detail, int $position = 1): OrderedShadowGate
    {
        return new OrderedShadowGate($position, new GateResult(
            $code,
            false,
            GateScope::Request,
            new GateSubjectId(str_repeat('s', 16)),
            null,
            $detail,
        ));
    }
}
