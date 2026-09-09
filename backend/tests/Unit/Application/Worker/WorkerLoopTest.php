<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Worker;

use App\Application\Readiness\ReadinessAggregator;
use App\Application\Readiness\ReadinessCheck;
use App\Application\Readiness\ReadinessCheckResult;
use App\Application\Worker\WorkerLoop;
use App\Application\Worker\WorkerReadinessProbe;
use App\Application\Worker\WorkerReadinessReport;
use App\Domain\Shared\Clock;
use App\Domain\Worker\WorkerKind;
use App\Tests\Fakes\RecordingSleeper;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerLoopTest extends TestCase
{
    public function testOnceRunsExactlyOneIterationWithoutSleeping(): void
    {
        $sleeper = new RecordingSleeper();
        $reports = [];
        $loop = $this->loop($sleeper);

        $report = $loop->run(
            WorkerKind::Collector,
            120,
            true,
            static function (WorkerReadinessReport $report) use (&$reports): void {
                $reports[] = $report->worker;
            },
        );

        self::assertSame([WorkerKind::Collector], $reports);
        self::assertSame([], $sleeper->durations);
        self::assertTrue($report->isReady());
    }

    public function testRecurringModeUsesTheConfiguredInterval(): void
    {
        $sleeper = new RecordingSleeper(interruptAfterCalls: 2);
        $iterations = 0;
        $loop = $this->loop($sleeper);

        try {
            $loop->run(
                WorkerKind::Backup,
                7,
                false,
                static function () use (&$iterations): void {
                    ++$iterations;
                },
            );
            self::fail('The recording sleeper should interrupt the test loop.');
        } catch (RuntimeException $exception) {
            self::assertSame('Test loop interrupted.', $exception->getMessage());
        }

        self::assertSame(2, $iterations);
        self::assertSame([7, 7], $sleeper->durations);
    }

    public function testItRejectsAnInvalidIntervalBeforeRunning(): void
    {
        $sleeper = new RecordingSleeper();
        $loop = $this->loop($sleeper);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The worker interval must be at least one second.');

        $loop->run(WorkerKind::Collector, 0, true, static function (): void {
        });
    }

    public function testOnceReturnsUnavailableWithoutRunningWorkOrSleeping(): void
    {
        $sleeper = new RecordingSleeper();
        $iterations = 0;
        $loop = $this->loop(
            $sleeper,
            ReadinessCheckResult::unavailable('database_schema', 'migration_version_mismatch'),
        );

        $report = $loop->run(
            WorkerKind::Collector,
            120,
            true,
            static function () use (&$iterations): void {
                ++$iterations;
            },
        );

        self::assertFalse($report->isReady());
        self::assertSame(0, $iterations);
        self::assertSame([], $sleeper->durations);
    }

    public function testRecurringUnavailableWorkerRetriesOnlyAfterSleeping(): void
    {
        $sleeper = new RecordingSleeper(interruptAfterCalls: 2);
        $iterations = 0;
        $loop = $this->loop(
            $sleeper,
            ReadinessCheckResult::unavailable('database_schema', 'migration_metadata_missing'),
        );

        try {
            $loop->run(
                WorkerKind::Backup,
                9,
                false,
                static function () use (&$iterations): void {
                    ++$iterations;
                },
            );
            self::fail('The recording sleeper should interrupt the retry loop.');
        } catch (RuntimeException $exception) {
            self::assertSame('Test loop interrupted.', $exception->getMessage());
        }

        self::assertSame(0, $iterations);
        self::assertSame([9, 9], $sleeper->durations);
    }

    private function loop(
        RecordingSleeper $sleeper,
        ?ReadinessCheckResult $result = null,
    ): WorkerLoop
    {
        $clock = new BudgetedWorkerClock(new DateTimeImmutable('2026-07-09T10:11:12+00:00'));

        return new WorkerLoop(
            new WorkerReadinessProbe(
                $clock,
                new ReadinessAggregator([
                    new BudgetedWorkerReadinessCheck(
                        $result ?? ReadinessCheckResult::ready('database_schema'),
                    ),
                ]),
            ),
            $sleeper,
        );
    }
}

final class BudgetedWorkerClock implements Clock
{
    private int $calls = 0;

    public function __construct(
        private readonly DateTimeImmutable $now,
        private readonly int $maximumCalls = 64,
    ) {}

    public function now(): DateTimeImmutable
    {
        ++$this->calls;
        if ($this->calls > $this->maximumCalls) {
            throw new RuntimeException('Worker clock call budget exceeded.');
        }

        return $this->now;
    }
}

final class BudgetedWorkerReadinessCheck implements ReadinessCheck
{
    private int $calls = 0;

    public function __construct(
        private readonly ReadinessCheckResult $result,
        private readonly int $maximumCalls = 64,
    ) {}

    public function name(): string
    {
        return 'database_schema';
    }

    public function check(): ReadinessCheckResult
    {
        ++$this->calls;
        if ($this->calls > $this->maximumCalls) {
            throw new RuntimeException('Worker readiness call budget exceeded.');
        }

        return $this->result;
    }
}
