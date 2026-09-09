<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scheduler\Shadow;

use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Scheduler\Shadow\ReadModel\ShadowDecisionDetail;
use App\Application\Scheduler\Shadow\ReadModel\ShadowDecisionPage;
use App\Application\Scheduler\Shadow\ReadModel\ShadowDecisionSummary;
use App\Application\Scheduler\Shadow\ReadModel\ShadowEvaluationPage;
use App\Application\Scheduler\Shadow\ReadModel\ShadowEvaluationSummary;
use App\Application\Scheduler\Shadow\ReadModel\ShadowGateDetail;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShadowReadModelTypesTest extends TestCase
{
    private const string ID = '00112233-4455-6677-8899-aabbccddeeff';

    public function testClosedDecisionAndGateDetailsSerializeWithoutDomainObjects(): void
    {
        $summary = $this->decision();
        $gate = new ShadowGateDetail(
            1,
            GateCode::InventoryFresh,
            false,
            GateScope::Inventory,
            self::ID,
            '2026-07-12T10:00:00.000000Z',
            GateDetailCode::Stale,
        );

        self::assertSame('eligible', $summary->toArray()['outcome']);
        self::assertSame('never_backed_up', $summary->toArray()['reason']);
        self::assertSame([
            'position' => 1,
            'code' => 'inventory_fresh',
            'passed' => false,
            'scope' => 'inventory',
            'subjectId' => self::ID,
            'observedAt' => '2026-07-12T10:00:00.000000Z',
            'detailCode' => 'stale',
        ], $gate->toArray());
        self::assertSame([$gate->toArray()], (new ShadowDecisionDetail($summary, [$gate]))->toArray()['gates']);
    }

    #[DataProvider('invalidGateProvider')]
    public function testGateDetailsRejectInvalidPositionOrPassMismatch(
        int $position,
        bool $passed,
        GateDetailCode $detail,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new ShadowGateDetail(
            $position,
            GateCode::InventoryFresh,
            $passed,
            GateScope::Inventory,
            self::ID,
            null,
            $detail,
        );
    }

    /** @return iterable<string, array{int, bool, GateDetailCode}> */
    public static function invalidGateProvider(): iterable
    {
        yield 'zero position' => [0, true, GateDetailCode::Passed];
        yield 'passed with blocker detail' => [1, true, GateDetailCode::Stale];
        yield 'failed with passed detail' => [1, false, GateDetailCode::Passed];
    }

    public function testPagesExposeStableMetadataForEmptyAndPopulatedResults(): void
    {
        $request = new PageRequest(20);
        $evaluation = new ShadowEvaluationSummary(
            self::ID,
            self::ID,
            1,
            2,
            3,
            4,
            '2026-07-12T09:59:59.000000Z',
            '2026-07-12T10:00:00.000000Z',
            '2026-07-12T10:00:00.000001Z',
        );

        $evaluationPage = (new ShadowEvaluationPage($request, [$evaluation], null))->toArray()['page'];
        $decisionPage = (new ShadowDecisionPage($request, [], null))->toArray()['page'];
        self::assertIsArray($evaluationPage);
        self::assertIsArray($decisionPage);
        self::assertSame(1, $evaluationPage['count'] ?? null);
        self::assertFalse($decisionPage['hasMore'] ?? null);
    }

    private function decision(): ShadowDecisionSummary
    {
        return new ShadowDecisionSummary(
            self::ID,
            self::ID,
            self::ID,
            self::ID,
            DecisionOutcome::Eligible,
            BackupReason::NeverBackedUp,
            300,
            self::ID,
            2,
            self::ID,
            3,
            '2026-07-12T10:00:00.000000Z',
        );
    }
}
