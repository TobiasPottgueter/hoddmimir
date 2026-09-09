<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scheduler;

use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateScope;
use App\Domain\Scheduler\GateSubjectId;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceFreshnessPolicyTest extends TestCase
{
    public function testDefaultWindowAcceptsTheExactBoundaryAndRejectsTheFirstMicrosecondAfterIt(): void
    {
        $policy = new EvidenceFreshnessPolicy();
        $subject = new GateSubjectId(str_repeat('g', 16));
        $observedAt = new DateTimeImmutable('2026-07-12T12:00:00.000000Z');

        $boundary = $policy->evaluate(
            GateCode::PlacementFresh,
            GateScope::Placement,
            $subject,
            new DateTimeImmutable('2026-07-12T12:05:00.000000Z'),
            $observedAt,
        );
        $stale = $policy->evaluate(
            GateCode::PlacementFresh,
            GateScope::Placement,
            $subject,
            new DateTimeImmutable('2026-07-12T12:05:00.000001Z'),
            $observedAt,
        );

        self::assertSame(300, $policy->maximumAgeSeconds);
        self::assertTrue($boundary->passed);
        self::assertSame(GateDetailCode::Passed, $boundary->detailCode);
        self::assertFalse($stale->passed);
        self::assertSame(GateDetailCode::Stale, $stale->detailCode);
    }

    public function testMissingAndFutureEvidenceFailClosed(): void
    {
        $policy = new EvidenceFreshnessPolicy(120);
        $subject = new GateSubjectId(str_repeat('s', 16));
        $now = new DateTimeImmutable('2026-07-12T12:00:00Z');

        $missing = $policy->evaluate(
            GateCode::CapacityFresh,
            GateScope::Capacity,
            $subject,
            $now,
            null,
        );
        $future = $policy->evaluate(
            GateCode::ExecutorAuthorizationFresh,
            GateScope::Authorization,
            $subject,
            $now,
            new DateTimeImmutable('2026-07-12T12:00:00.000001Z'),
        );

        self::assertFalse($missing->passed);
        self::assertNull($missing->observedAt);
        self::assertSame(GateDetailCode::Missing, $missing->detailCode);
        self::assertFalse($future->passed);
        self::assertSame(GateDetailCode::Stale, $future->detailCode);
    }

    /** @return iterable<string, array{GateCode, GateScope}> */
    public static function supportedGates(): iterable
    {
        yield 'inventory' => [GateCode::InventoryFresh, GateScope::Inventory];
        yield 'placement' => [GateCode::PlacementFresh, GateScope::Placement];
        yield 'capacity' => [GateCode::CapacityFresh, GateScope::Capacity];
        yield 'authorization' => [GateCode::ExecutorAuthorizationFresh, GateScope::Authorization];
    }

    #[DataProvider('supportedGates')]
    public function testEveryRequiredEvidenceFamilyUsesTheSameWindow(GateCode $code, GateScope $scope): void
    {
        $result = (new EvidenceFreshnessPolicy(1))->evaluate(
            $code,
            $scope,
            new GateSubjectId(str_repeat('e', 16)),
            new DateTimeImmutable('2026-07-12T12:00:01+00:00'),
            new DateTimeImmutable('2026-07-12T14:00:00+02:00'),
        );

        self::assertTrue($result->passed);
        self::assertSame('+00:00', $result->observedAt?->format('P'));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidWindows(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'over one day' => [86401];
    }

    #[DataProvider('invalidWindows')]
    public function testWindowIsBounded(int $seconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EvidenceFreshnessPolicy($seconds);
    }

    public function testNonFreshnessGateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EvidenceFreshnessPolicy())->evaluate(
            GateCode::GuestActive,
            GateScope::Guest,
            new GateSubjectId(str_repeat('g', 16)),
            new DateTimeImmutable('2026-07-12T12:00:00Z'),
            new DateTimeImmutable('2026-07-12T12:00:00Z'),
        );
    }
}
