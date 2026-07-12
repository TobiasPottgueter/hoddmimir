<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scheduler\Shadow;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Scheduler\Shadow\PersistShadowEvaluation;
use App\Application\Scheduler\Shadow\ShadowEvaluationBatch;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflict;
use App\Application\Scheduler\Shadow\ShadowEvaluationConflictCode;
use App\Application\Scheduler\Shadow\ShadowEvaluationPersistenceResult;
use App\Application\Scheduler\Shadow\ShadowEvaluationRunId;
use App\Application\Scheduler\Shadow\ShadowEvaluationStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PersistShadowEvaluationTest extends TestCase
{
    public function testServiceDelegatesMatchingCycleAndReturnsStoreResult(): void
    {
        $token = new CollectorCycleToken(str_repeat("\x01", 16));
        $batch = $this->batch($token);
        $store = new RecordingShadowEvaluationStore(ShadowEvaluationPersistenceResult::AlreadyPersisted);

        $result = (new PersistShadowEvaluation($store))->execute($this->lease($token), $batch);

        self::assertSame(ShadowEvaluationPersistenceResult::AlreadyPersisted, $result);
        self::assertSame([[$batch]], $store->calls);
    }

    public function testServiceFailsClosedBeforeStoreForAnotherCycle(): void
    {
        $store = new RecordingShadowEvaluationStore(ShadowEvaluationPersistenceResult::Persisted);
        $service = new PersistShadowEvaluation($store);

        try {
            $service->execute(
                $this->lease(new CollectorCycleToken(str_repeat("\x01", 16))),
                $this->batch(new CollectorCycleToken(str_repeat("\x02", 16))),
            );
            self::fail('A batch from another collector cycle must be rejected.');
        } catch (ShadowEvaluationConflict $conflict) {
            self::assertSame(ShadowEvaluationConflictCode::CycleTokenMismatch, $conflict->failureCode);
        }
        self::assertSame([], $store->calls);
    }

    private function batch(CollectorCycleToken $token): ShadowEvaluationBatch
    {
        return new ShadowEvaluationBatch(
            new ShadowEvaluationRunId(str_repeat("\x03", 16)),
            $token,
            1,
            new DateTimeImmutable('2026-07-12T12:00:00Z'),
            new DateTimeImmutable('2026-07-12T12:00:01Z'),
            [],
        );
    }

    private function lease(CollectorCycleToken $token): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId(str_repeat("\x04", 16)),
            $token,
            1,
            new DateTimeImmutable('2026-07-12T12:05:00Z'),
        );
    }
}

final class RecordingShadowEvaluationStore implements ShadowEvaluationStore
{
    /** @var list<array{ShadowEvaluationBatch}> */
    public array $calls = [];

    public function __construct(private readonly ShadowEvaluationPersistenceResult $result)
    {
    }

    public function persist(
        CollectorLease $lease,
        ShadowEvaluationBatch $batch,
    ): ShadowEvaluationPersistenceResult {
        $this->calls[] = [$batch];
        return $this->result;
    }
}
