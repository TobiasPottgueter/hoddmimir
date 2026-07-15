<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Scheduler\Shadow\OrderedShadowGate;
use App\Application\Scheduler\Shadow\ShadowDecision;
use App\Application\Scheduler\Shadow\ShadowDecisionId;
use App\Application\Scheduler\Shadow\ShadowEvaluationBatch;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflict;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflictCode;
use App\Application\Scheduler\Shadow\ShadowEvaluationPersistenceResult;
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
use App\Infrastructure\Persistence\MariaDb\DbalShadowEvaluationStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalShadowEvaluationStoreTest extends TestCase
{
    public function testRejectsPolicyRevisionChangedBetweenSourceReadAndPersist(): void
    {
        $this->expectException(ShadowEvaluationConflict::class);
        $this->expectExceptionMessage('policy or target changed');
        (new DbalShadowEvaluationStore($this->database(['policy' => ['revision' => 4, 'status' => 'enabled']])))->persist($this->lease(), $this->batch());
    }
    private const string NOW = '2026-07-12 10:05:00.000000';

    public function testPersistsRunDecisionsAndOrderedGatesWithExactSqlMapping(): void
    {
        $inserts = [];
        $database = $this->database();
        $database->expects(self::exactly(5))->method('insert')->willReturnCallback(
            static function (string $table, array $values) use (&$inserts): int {
                $inserts[] = [$table, $values];

                return 1;
            },
        );

        $result = (new DbalShadowEvaluationStore($database))->persist($this->lease(), $this->batch());

        self::assertSame(ShadowEvaluationPersistenceResult::Persisted, $result);
        self::assertSame('scheduler_evaluation_runs', $inserts[0][0]);
        self::assertSame(2, count(array_filter($inserts, static fn (array $insert): bool => 'scheduler_decisions' === $insert[0])));
        self::assertSame(2, count(array_filter($inserts, static fn (array $insert): bool => 'scheduler_decision_gates' === $insert[0])));

        $run = $inserts[0][1];
        self::assertSame(2, $run['decision_count']);
        self::assertSame(2, $run['gate_count']);
        self::assertSame(self::NOW, $run['persisted_at']);
        self::assertIsString($run['payload_hash']);
        self::assertSame(32, strlen($run['payload_hash']));

        $decisions = array_values(array_map(
            static fn (array $insert): array => $insert[1],
            array_filter($inserts, static fn (array $insert): bool => 'scheduler_decisions' === $insert[0]),
        ));
        $gates = array_values(array_map(
            static fn (array $insert): array => $insert[1],
            array_filter($inserts, static fn (array $insert): bool => 'scheduler_decision_gates' === $insert[0]),
        ));
        $blocked = current(array_filter($decisions, static fn (array $row): bool => 'blocked' === $row['outcome']));
        self::assertIsArray($blocked);
        self::assertContains($blocked['decision_ordinal'], [1, 2]);
        self::assertSame('blocked', $blocked['outcome']);
        self::assertNull($blocked['node_id']);
        self::assertNull($blocked['policy_id']);
        self::assertNull($blocked['target_id']);
        self::assertNull($blocked['capacity_observed_at']);
        self::assertNull($blocked['write_state_observed_at']);

        $blockedGate = current(array_filter($gates, static fn (array $row): bool => 'placement_present' === $row['code']));
        self::assertIsArray($blockedGate);
        self::assertSame(1, $blockedGate['gate_ordinal']);
        self::assertSame('placement_present', $blockedGate['code']);
        self::assertSame(0, $blockedGate['passed']);
        self::assertSame('missing', $blockedGate['detail_code']);
        self::assertNull($blockedGate['observed_at']);

        $deduplicated = current(array_filter($decisions, static fn (array $row): bool => 'deduplicated' === $row['outcome']));
        self::assertIsArray($deduplicated);
        self::assertContains($deduplicated['decision_ordinal'], [1, 2]);
        self::assertNotSame($blocked['decision_ordinal'], $deduplicated['decision_ordinal']);
        self::assertSame('deduplicated', $deduplicated['outcome']);
        self::assertSame(300, $deduplicated['priority']);
        self::assertSame('never_backed_up', $deduplicated['reason']);
        self::assertSame(9, $deduplicated['placement_revision']);
        self::assertSame(3, $deduplicated['policy_revision']);
        self::assertSame(4, $deduplicated['target_revision']);
        self::assertSame(hash('sha256', 'policy', true), $deduplicated['policy_snapshot_hash']);
        self::assertSame('2026-07-12 10:00:10.000000', $deduplicated['capacity_observed_at']);
        self::assertSame('2026-07-12 10:00:20.000000', $deduplicated['write_state_observed_at']);

        $deduplicationGate = current(array_filter($gates, static fn (array $row): bool => 'higher_ranked_candidate_absent' === $row['code']));
        self::assertIsArray($deduplicationGate);
        self::assertSame(0, $deduplicationGate['passed']);
        self::assertSame('higher_ranked_candidate', $deduplicationGate['detail_code']);
        self::assertSame('2026-07-12 10:00:00.000000', $deduplicationGate['observed_at']);
    }

    public function testPersistsAnEmptyEvaluationWithoutChildRows(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('insert')->with(
            'scheduler_evaluation_runs',
            self::callback(static fn (array $row): bool => 0 === $row['decision_count'] && 0 === $row['gate_count']),
        );

        $result = (new DbalShadowEvaluationStore($database))->persist(
            $this->lease(),
            $this->batch([]),
        );

        self::assertSame(ShadowEvaluationPersistenceResult::Persisted, $result);
    }

    public function testExactReplayIsAnIdempotentNoopAndAcceptsNumericStrings(): void
    {
        $batch = $this->batch();
        $database = $this->database(['existing' => $this->existingRow($batch, [
            'collector_fencing_token' => '7',
            'evaluator_version' => '1',
            'decision_count' => '2',
            'gate_count' => '2',
        ])]);
        $database->expects(self::never())->method('insert');

        $result = (new DbalShadowEvaluationStore($database))->persist($this->lease(), $batch);

        self::assertSame(ShadowEvaluationPersistenceResult::AlreadyPersisted, $result);
    }

    /** @return iterable<string, array{array<string, mixed>, ShadowEvaluationConflictCode}> */
    public static function replayConflicts(): iterable
    {
        yield 'another run already owns cycle' => [['id' => str_repeat('x', 16)], ShadowEvaluationConflictCode::CycleAlreadyEvaluated];
        yield 'run id reused for another cycle' => [['cycle_token' => str_repeat('x', 16)], ShadowEvaluationConflictCode::RunIdReused];
        yield 'different fence' => [['collector_fencing_token' => 8], ShadowEvaluationConflictCode::PayloadMismatch];
        yield 'different evaluator' => [['evaluator_version' => 2], ShadowEvaluationConflictCode::PayloadMismatch];
        yield 'different hash' => [['payload_hash' => str_repeat('x', 32)], ShadowEvaluationConflictCode::PayloadMismatch];
        yield 'non-binary hash' => [['payload_hash' => 1], ShadowEvaluationConflictCode::PayloadMismatch];
        yield 'different decision count' => [['decision_count' => 1], ShadowEvaluationConflictCode::PayloadMismatch];
        yield 'different gate count' => [['gate_count' => 1], ShadowEvaluationConflictCode::PayloadMismatch];
        yield 'different start' => [['started_at' => '2026-07-12 09:59:59.000000'], ShadowEvaluationConflictCode::PayloadMismatch];
        yield 'different completion' => [['completed_at' => '2026-07-12 10:02:00.000000'], ShadowEvaluationConflictCode::PayloadMismatch];
    }

    /** @param array<string, mixed> $replacement */
    #[DataProvider('replayConflicts')]
    public function testRejectsReplayConflicts(array $replacement, ShadowEvaluationConflictCode $expected): void
    {
        $batch = $this->batch();
        $database = $this->database(['existing' => $this->existingRow($batch, $replacement)]);
        $database->expects(self::never())->method('insert');

        try {
            (new DbalShadowEvaluationStore($database))->persist($this->lease(), $batch);
            self::fail('A conflicting shadow replay was accepted.');
        } catch (ShadowEvaluationConflict $failure) {
            self::assertSame($expected, $failure->failureCode);
        }
    }

    public function testRejectsBatchFromAnotherCycleBeforeOpeningATransaction(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('transactional');
        $batch = new ShadowEvaluationBatch(
            new ShadowEvaluationRunId(self::bytes('run')),
            new CollectorCycleToken(self::bytes('other-cycle')),
            1,
            new DateTimeImmutable('2026-07-12T10:00:00Z'),
            new DateTimeImmutable('2026-07-12T10:01:00Z'),
            [],
        );

        try {
            (new DbalShadowEvaluationStore($database))->persist($this->lease(), $batch);
            self::fail('A foreign collector cycle was accepted.');
        } catch (ShadowEvaluationConflict $failure) {
            self::assertSame(ShadowEvaluationConflictCode::CycleTokenMismatch, $failure->failureCode);
        }
    }

    public function testRejectsEvaluationCompletedAfterDatabaseClock(): void
    {
        $database = $this->database(['clock' => '2026-07-12 10:00:30.000000']);
        $database->expects(self::never())->method('insert');

        try {
            (new DbalShadowEvaluationStore($database))->persist($this->lease(), $this->batch([]));
            self::fail('A future shadow evaluation was accepted.');
        } catch (ShadowEvaluationConflict $failure) {
            self::assertSame(ShadowEvaluationConflictCode::PayloadMismatch, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, class-string<\Throwable>}> */
    public static function invalidFenceRows(): iterable
    {
        yield 'missing schedule' => [['schedule' => false], CollectorLeaseOwnershipLost::class];
        yield 'missing cycle' => [['cycle' => false], CollectorLeaseOwnershipLost::class];
        yield 'wrong owner' => [['schedule' => ['lease_owner' => str_repeat('x', 16)]], CollectorLeaseOwnershipLost::class];
        yield 'wrong token' => [['schedule' => ['lease_token' => str_repeat('x', 16)]], CollectorLeaseOwnershipLost::class];
        yield 'expired lease' => [['schedule' => ['lease_expires_at' => self::NOW]], CollectorLeaseOwnershipLost::class];
        yield 'wrong schedule fence' => [['schedule' => ['lease_fencing_token' => 8]], CollectorLeaseOwnershipLost::class];
        yield 'invalid schedule fence' => [['schedule' => ['lease_fencing_token' => 'bad']], RuntimeException::class];
        yield 'finished cycle' => [['cycle' => ['status' => 'succeeded']], CollectorLeaseOwnershipLost::class];
        yield 'wrong cycle owner' => [['cycle' => ['worker_instance_id' => str_repeat('x', 16)]], CollectorLeaseOwnershipLost::class];
        yield 'wrong worker kind' => [['cycle' => ['worker_kind' => 'backup']], CollectorLeaseOwnershipLost::class];
        yield 'wrong cycle fence' => [['cycle' => ['fencing_token' => 8]], CollectorLeaseOwnershipLost::class];
        yield 'invalid cycle fence' => [['cycle' => ['fencing_token' => null]], RuntimeException::class];
        yield 'invalid clock type' => [['clock' => false], RuntimeException::class];
        yield 'invalid clock value' => [['clock' => 'invalid'], RuntimeException::class];
        yield 'invalid expiry value' => [['schedule' => ['lease_expires_at' => 'invalid']], RuntimeException::class];
    }

    /**
     * @param array<string, mixed> $options
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidFenceRows')]
    public function testFailsClosedForLostFenceAndMalformedRows(array $options, string $exception): void
    {
        $this->expectException($exception);
        (new DbalShadowEvaluationStore($this->database($options)))->persist($this->lease(), $this->batch([]));
    }

    public function testSecondFenceCheckRollsBackWhenOwnershipIsLostAfterWrites(): void
    {
        $database = $this->database(['lose_final_fence' => true]);
        $database->expects(self::once())->method('insert')->with('scheduler_evaluation_runs', self::anything());

        $this->expectException(CollectorLeaseOwnershipLost::class);
        (new DbalShadowEvaluationStore($database))->persist($this->lease(), $this->batch([]));
    }

    public function testInsertFailureEscapesTransactionAndStopsChildMapping(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('insert')->willThrowException(new RuntimeException('insert failed'));

        $this->expectExceptionMessage('insert failed');
        (new DbalShadowEvaluationStore($database))->persist($this->lease(), $this->batch());
    }

    /** @param array<string, mixed> $options */
    private function database(array $options = []): Connection&MockObject
    {
        $lease = $this->lease();
        $options += [
            'schedule' => [],
            'cycle' => [],
            'existing' => false,
            'clock' => self::NOW,
            'lose_final_fence' => false,
            'policy' => ['revision' => 3, 'status' => 'enabled'],
            'target' => ['revision' => 4, 'status' => 'enabled'],
        ];
        $schedule = false === $options['schedule'] ? false : array_replace([
            'lease_owner' => $lease->ownerId->bytes,
            'lease_token' => $lease->token->binary(),
            'lease_fencing_token' => 7,
            'lease_expires_at' => '2099-01-01 00:00:00.000000',
        ], is_array($options['schedule']) ? $options['schedule'] : []);
        $cycle = false === $options['cycle'] ? false : array_replace([
            'status' => 'running',
            'worker_instance_id' => $lease->ownerId->bytes,
            'worker_kind' => 'collector',
            'fencing_token' => 7,
        ], is_array($options['cycle']) ? $options['cycle'] : []);
        $scheduleReads = 0;

        $database = $this->createMock(Connection::class);
        $database->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation($database),
        );
        $database->method('fetchAssociative')->willReturnCallback(
            static function (string $sql) use ($schedule, $cycle, $options, &$scheduleReads): array|false {
                if (str_contains($sql, 'FROM collector_schedule')) {
                    ++$scheduleReads;
                    if (true === $options['lose_final_fence'] && $scheduleReads > 1 && is_array($schedule)) {
                        return array_replace($schedule, ['lease_token' => str_repeat('x', 16)]);
                    }

                    return $schedule;
                }
                if (str_contains($sql, 'FROM collector_cycles')) {
                    return $cycle;
                }
                if (str_contains($sql, 'FROM scheduler_evaluation_runs')) {
                    return is_array($options['existing']) ? $options['existing'] : false;
                }
                if (str_contains($sql, 'FROM backup_policies')) {
                    return is_array($options['policy']) ? $options['policy'] : false;
                }
                if (str_contains($sql, 'FROM backup_targets')) {
                    return is_array($options['target']) ? $options['target'] : false;
                }

                return false;
            },
        );
        $database->method('fetchOne')->willReturn($options['clock']);

        return $database;
    }

    /** @param list<ShadowDecision>|null $decisions */
    private function batch(?array $decisions = null): ShadowEvaluationBatch
    {
        return new ShadowEvaluationBatch(
            new ShadowEvaluationRunId(self::bytes('run')),
            $this->lease()->token,
            1,
            new DateTimeImmutable('2026-07-12T10:00:00Z'),
            new DateTimeImmutable('2026-07-12T10:01:00Z'),
            $decisions ?? [$this->deduplicatedDecision(), $this->blockedDecision()],
        );
    }

    private function deduplicatedDecision(): ShadowDecision
    {
        return new ShadowDecision(
            new ShadowDecisionId(self::bytes('decision-z')),
            self::id('connection'),
            self::id('cluster'),
            self::id('guest-z'),
            new ShadowPlacementEvidence(self::id('node'), 9, new DateTimeImmutable('2026-07-12T10:00:00Z')),
            DecisionOutcome::Deduplicated,
            new ReasonPriority(BackupReason::NeverBackedUp, Priority::NeverBackedUp),
            new ShadowPolicyEvidence(self::id('policy'), 3, hash('sha256', 'policy', true)),
            new ShadowTargetEvidence(self::id('target'), 4),
            new DateTimeImmutable('2026-07-12T10:00:00Z'),
            new DateTimeImmutable('2026-07-12T10:00:10Z'),
            new DateTimeImmutable('2026-07-12T10:00:20Z'),
            [new OrderedShadowGate(1, new GateResult(
                GateCode::HigherRankedCandidateAbsent,
                false,
                GateScope::Request,
                new GateSubjectId(self::id('connection')->binary()),
                new DateTimeImmutable('2026-07-12T12:00:00+02:00'),
                GateDetailCode::HigherRankedCandidate,
            ))],
        );
    }

    private function blockedDecision(): ShadowDecision
    {
        return new ShadowDecision(
            new ShadowDecisionId(self::bytes('decision-a')),
            self::id('connection'),
            self::id('cluster'),
            self::id('guest-a'),
            null,
            DecisionOutcome::Blocked,
            null,
            null,
            null,
            new DateTimeImmutable('2026-07-12T12:00:00+02:00'),
            null,
            null,
            [new OrderedShadowGate(1, new GateResult(
                GateCode::PlacementPresent,
                false,
                GateScope::Placement,
                new GateSubjectId(self::id('guest-a')->binary()),
                null,
                GateDetailCode::Missing,
            ))],
        );
    }

    /**
     * @param array<string, mixed> $replacement
     *
     * @return array<string, mixed>
     */
    private function existingRow(ShadowEvaluationBatch $batch, array $replacement = []): array
    {
        return array_replace([
            'id' => $batch->runId->binary(),
            'cycle_token' => $batch->cycleToken->binary(),
            'collector_fencing_token' => 7,
            'evaluator_version' => $batch->evaluatorVersion,
            'payload_hash' => $batch->contentHash(),
            'decision_count' => count($batch->decisions),
            'gate_count' => $batch->gateCount(),
            'started_at' => '2026-07-12 10:00:00.000000',
            'completed_at' => '2026-07-12 10:01:00.000000',
        ], $replacement);
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId(self::bytes('worker')),
            new CollectorCycleToken(self::bytes('cycle')),
            7,
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
        );
    }

    private static function id(string $label): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes($label));
    }

    private static function bytes(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }
}
