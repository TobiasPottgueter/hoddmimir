<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scheduler;

use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateResult;
use App\Domain\Scheduler\GateScope;
use App\Domain\Scheduler\GateSubjectId;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClosedDecisionTypesTest extends TestCase
{
    public function testDecisionOutcomesAreClosedAndStable(): void
    {
        self::assertSame(
            ['eligible', 'blocked', 'not_due', 'deduplicated'],
            array_column(DecisionOutcome::cases(), 'value'),
        );
    }

    public function testGateAndExplainabilityCodesAreClosedAndStable(): void
    {
        self::assertSame([
            'connection_enabled',
            'cluster_enabled',
            'node_enabled',
            'guest_enabled',
            'policy_enabled',
            'policy_retention_compatible',
            'target_enabled',
            'explicit_exclusion_absent',
            'guest_active',
            'inventory_fresh',
            'placement_present',
            'placement_fresh',
            'active_request_absent',
            'higher_ranked_candidate_absent',
            'target_node_allowed',
            'target_storage_enabled',
            'target_storage_active',
            'executor_authorization_fresh',
            'executor_authorized',
            'capacity_fresh',
            'minimum_free_space',
            'node_concurrency',
            'target_concurrency',
            'pbs_mapping_valid',
        ], array_column(GateCode::cases(), 'value'));

        self::assertSame([
            'connection', 'cluster', 'node', 'guest', 'policy', 'target', 'inventory',
            'placement', 'authorization', 'capacity', 'concurrency', 'request', 'pbs_mapping',
        ], array_column(GateScope::cases(), 'value'));
        self::assertSame([
            'passed', 'disabled', 'explicitly_excluded', 'archived', 'missing', 'stale',
            'not_allowed', 'inactive', 'unauthorized', 'insufficient_free_space',
            'concurrency_limit_reached', 'invalid_mapping', 'incompatible', 'active_request_exists',
            'higher_ranked_candidate',
        ], array_column(GateDetailCode::cases(), 'value'));
    }

    public function testGateResultsRetainCompleteTypedPassAndFailureEvidence(): void
    {
        $connection = new GateSubjectId(str_repeat('c', 16));
        $mapping = new GateSubjectId(str_repeat('m', 16));
        $passed = new GateResult(
            GateCode::ConnectionEnabled,
            true,
            GateScope::Connection,
            $connection,
            null,
            GateDetailCode::Passed,
        );
        $failed = new GateResult(
            GateCode::PbsMappingValid,
            false,
            GateScope::PbsMapping,
            $mapping,
            new DateTimeImmutable('2026-07-12T12:00:00+02:00'),
            GateDetailCode::InvalidMapping,
        );

        self::assertSame(GateCode::ConnectionEnabled, $passed->code);
        self::assertTrue($passed->passed);
        self::assertSame(GateScope::Connection, $passed->scope);
        self::assertSame($connection, $passed->subjectId);
        self::assertSame(str_repeat('c', 16), $passed->subjectId->binary());
        self::assertNull($passed->observedAt);
        self::assertSame(GateDetailCode::Passed, $passed->detailCode);
        self::assertSame(GateCode::PbsMappingValid, $failed->code);
        self::assertFalse($failed->passed);
        self::assertSame(GateScope::PbsMapping, $failed->scope);
        self::assertSame($mapping, $failed->subjectId);
        self::assertNotNull($failed->observedAt);
        self::assertSame('+00:00', $failed->observedAt->format('P'));
        self::assertSame(GateDetailCode::InvalidMapping, $failed->detailCode);
    }

    /** @return iterable<string, array{bool, GateDetailCode}> */
    public static function inconsistentPassState(): iterable
    {
        yield 'passed with failure detail' => [true, GateDetailCode::Disabled];
        yield 'failed with passed detail' => [false, GateDetailCode::Passed];
    }

    #[DataProvider('inconsistentPassState')]
    public function testGateDetailMustAgreeWithPassState(bool $passed, GateDetailCode $detail): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GateResult(
            GateCode::ConnectionEnabled,
            $passed,
            GateScope::Connection,
            new GateSubjectId(str_repeat('c', 16)),
            null,
            $detail,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSubjectIds(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 15)];
        yield 'long' => [str_repeat('a', 17)];
    }

    #[DataProvider('invalidSubjectIds')]
    public function testGateSubjectIdIsExactlySixteenBytes(string $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GateSubjectId($bytes);
    }
}
