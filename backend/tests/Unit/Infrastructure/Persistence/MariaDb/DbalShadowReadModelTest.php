<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Scheduler\Shadow\ReadModel\ShadowPageQuery;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\DecisionOutcome;
use App\Infrastructure\Persistence\MariaDb\DbalShadowReadModel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DbalShadowReadModelTest extends TestCase
{
    private const string ID = '00112233-4455-6677-8899-aabbccddeeff';
    private const string COMPLETED = '2026-07-12T10:00:01.000000Z';

    public function testEvaluationProjectionUsesDescendingKeysetAndOpaqueCursor(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::stringContains('ORDER BY completed_at DESC, id DESC'),
                ['limit' => 2],
                self::anything(),
            )
            ->willReturn([$this->evaluationRow(), $this->evaluationRow(str_repeat("\x11", 16))]);

        $page = (new DbalShadowReadModel($database))->evaluations(
            new ShadowPageQuery(new PageRequest(1), 'shadow-evaluations-v1'),
        );

        self::assertCount(1, $page->items);
        self::assertSame(7, $page->items[0]->fencingToken);
        self::assertNotNull($page->nextCursor);
    }

    public function testEvaluationCursorBindsTimestampAndBinaryId(): void
    {
        $base = new ShadowPageQuery(new PageRequest(1), 'shadow-evaluations-v1');
        $cursor = PageCursor::resource($base->cursorContext(), self::COMPLETED, self::ID);
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::stringContains('completed_at < :at'),
                self::callback(static fn (array $parameters): bool => '2026-07-12 10:00:01.000000' === $parameters['at']
                    && hex2bin('00112233445566778899aabbccddeeff') === $parameters['id']),
                self::anything(),
            )
            ->willReturn([]);

        $page = (new DbalShadowReadModel($database))->evaluations(
            new ShadowPageQuery(new PageRequest(1, $cursor), 'shadow-evaluations-v1'),
        );

        self::assertSame([], $page->items);
        self::assertNull($page->nextCursor);
    }

    public function testDecisionProjectionMapsClosedEnumsAndNullableFields(): void
    {
        $row = $this->decisionRow();
        $row['node_id'] = null;
        $row['reason'] = null;
        $row['priority'] = null;
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);

        $page = (new DbalShadowReadModel($database))->decisions(
            new ShadowPageQuery(new PageRequest(20), 'shadow-decisions-v1'),
        );

        self::assertSame('eligible', $page->items[0]->outcome->value);
        self::assertNull($page->items[0]->reason);
        self::assertNull($page->items[0]->nodeId);
        self::assertNull($page->items[0]->priority);
    }

    public function testDecisionProjectionBindsEveryClosedFilterAndCursorContext(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'decision.outcome = :outcome')
                    && str_contains($sql, 'decision.reason = :reason')
                    && str_contains($sql, 'decision.policy_id = :policyId')
                    && str_contains($sql, 'decision.target_id = :targetId')
                    && str_contains($sql, 'decision.guest_id = :guestId')),
                self::callback(static fn (array $parameters): bool => 'eligible' === $parameters['outcome']
                    && 'never_backed_up' === $parameters['reason']
                    && hex2bin('44112233445566778899aabbccddeeff') === $parameters['policyId']
                    && hex2bin('55112233445566778899aabbccddeeff') === $parameters['targetId']
                    && hex2bin('22112233445566778899aabbccddeeff') === $parameters['guestId']),
                self::anything(),
            )
            ->willReturn([$this->decisionRow()]);

        $query = new ShadowPageQuery(
            new PageRequest(20),
            'shadow-decisions-v1',
            DecisionOutcome::Eligible,
            BackupReason::NeverBackedUp,
            '44112233-4455-6677-8899-aabbccddeeff',
            '55112233-4455-6677-8899-aabbccddeeff',
            '22112233-4455-6677-8899-aabbccddeeff',
        );
        $page = (new DbalShadowReadModel($database))->decisions($query);

        self::assertCount(1, $page->items);
        self::assertNotSame(
            (new ShadowPageQuery(new PageRequest(20), 'shadow-decisions-v1'))->cursorContext(),
            $query->cursorContext(),
        );
    }

    public function testDecisionDetailMapsOrderedClosedGates(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAssociative')->willReturn($this->decisionRow());
        $database->expects(self::once())->method('fetchAllAssociative')
            ->with(self::stringContains('ORDER BY gate_ordinal'), self::anything(), self::anything())
            ->willReturn([
                [
                    'position' => '1',
                    'code' => 'inventory_fresh',
                    'passed' => '0',
                    'scope' => 'inventory',
                    'subject_id' => hex2bin('00112233445566778899aabbccddeeff'),
                    'observed_at' => '2026-07-12 10:00:00.000000',
                    'detail_code' => 'stale',
                ],
                [
                    'position' => '2',
                    'code' => 'connection_enabled',
                    'passed' => '1',
                    'scope' => 'connection',
                    'subject_id' => hex2bin('11112233445566778899aabbccddeeff'),
                    'observed_at' => null,
                    'detail_code' => 'passed',
                ],
            ]);

        $detail = (new DbalShadowReadModel($database))->decision(self::ID);

        self::assertNotNull($detail);
        self::assertSame(['inventory_fresh', 'connection_enabled'], array_map(
            static fn ($gate): string => $gate->code->value,
            $detail->gates,
        ));
        self::assertSame('2026-07-12T10:00:00.000000Z', $detail->gates[0]->observedAt);
    }

    public function testMissingDecisionReturnsNullWithoutLoadingGates(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAssociative')->willReturn(false);
        $database->expects(self::never())->method('fetchAllAssociative');

        self::assertNull((new DbalShadowReadModel($database))->decision(self::ID));
    }

    public function testIntegerAbovePlatformMaximumFailsClosed(): void
    {
        $row = $this->evaluationRow();
        $row['collector_fencing_token'] = '9223372036854775808';
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);

        $this->expectException(RuntimeException::class);
        (new DbalShadowReadModel($database))->evaluations(
            new ShadowPageQuery(new PageRequest(20), 'shadow-evaluations-v1'),
        );
    }

    public function testPaddedPlatformMaximumAndNativeZeroAreAcceptedLosslessly(): void
    {
        $row = $this->evaluationRow();
        $row['collector_fencing_token'] = '000'.PHP_INT_MAX;
        $row['decision_count'] = 0;
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([$row]);

        $item = (new DbalShadowReadModel($database))->evaluations(
            new ShadowPageQuery(new PageRequest(20), 'shadow-evaluations-v1'),
        )->items[0];

        self::assertSame(PHP_INT_MAX, $item->fencingToken);
        self::assertSame(0, $item->decisionCount);
    }

    public function testMalformedCursorTimestampFailsClosedBeforeDatabaseCall(): void
    {
        $base = new ShadowPageQuery(new PageRequest(1), 'shadow-decisions-v1');
        $cursor = PageCursor::resource($base->cursorContext(), 'not-a-date', self::ID);
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchAllAssociative');

        $this->expectException(RuntimeException::class);
        (new DbalShadowReadModel($database))->decisions(
            new ShadowPageQuery(new PageRequest(1, $cursor), 'shadow-decisions-v1'),
        );
    }

    /** @return array<string, mixed> */
    private function evaluationRow(string $id = "\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff"): array
    {
        return [
            'id' => $id,
            'cycle_token' => hex2bin('11112233445566778899aabbccddeeff'),
            'collector_fencing_token' => '7',
            'evaluator_version' => '1',
            'decision_count' => '2',
            'gate_count' => '3',
            'started_at' => '2026-07-12 10:00:00.000000',
            'completed_at' => '2026-07-12 10:00:01.000000',
            'persisted_at' => '2026-07-12 10:00:01.000001',
        ];
    }

    /** @return array<string, mixed> */
    private function decisionRow(): array
    {
        return [
            'id' => hex2bin('00112233445566778899aabbccddeeff'),
            'evaluation_run_id' => hex2bin('11112233445566778899aabbccddeeff'),
            'guest_id' => hex2bin('22112233445566778899aabbccddeeff'),
            'node_id' => hex2bin('33112233445566778899aabbccddeeff'),
            'outcome' => 'eligible',
            'reason' => 'never_backed_up',
            'priority' => '300',
            'policy_id' => hex2bin('44112233445566778899aabbccddeeff'),
            'policy_revision' => '2',
            'target_id' => hex2bin('55112233445566778899aabbccddeeff'),
            'target_revision' => '3',
            'completed_at' => '2026-07-12 10:00:01.000000',
        ];
    }
}
