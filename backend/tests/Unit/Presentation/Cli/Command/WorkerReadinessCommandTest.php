<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Cli\Command;

use App\Application\Readiness\ReadinessAggregator;
use App\Application\Readiness\ReadinessCheckResult;
use App\Application\Worker\WorkerReadinessProbe;
use App\Presentation\Cli\Command\WorkerReadinessCommand;
use App\Tests\Fakes\FrozenClock;
use App\Tests\Fakes\FixedReadinessCheck;
use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerReadinessCommandTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function workerKinds(): iterable
    {
        yield 'collector' => ['collector'];
        yield 'backup' => ['backup'];
    }

    /** @throws JsonException */
    #[DataProvider('workerKinds')]
    public function testItReportsReadinessWithoutRunningTheWorkerLoop(string $worker): void
    {
        $tester = $this->createCommandTester();

        self::assertSame(0, $tester->execute(['worker' => $worker]));
        self::assertSame(
            [
                'component' => $worker,
                'status' => 'ready',
                'checkedAt' => '2026-07-09T10:11:12.456+00:00',
                'checks' => [
                    'database_schema' => ['status' => 'ready'],
                ],
            ],
            json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testItRejectsAnUnknownWorker(): void
    {
        $tester = $this->createCommandTester();

        self::assertSame(2, $tester->execute(['worker' => 'unknown']));
        self::assertStringContainsString(
            'either "collector" or "backup"',
            $tester->getDisplay(),
        );
    }

    public function testItFailsWhenARequirementIsUnavailable(): void
    {
        $tester = new CommandTester(new WorkerReadinessCommand(new WorkerReadinessProbe(
            new FrozenClock(new DateTimeImmutable('2026-07-09T10:11:12.456+00:00')),
            new ReadinessAggregator([
                new FixedReadinessCheck(ReadinessCheckResult::unavailable(
                    'database_schema',
                    'migration_version_mismatch',
                )),
            ]),
        )));

        self::assertSame(1, $tester->execute(['worker' => 'collector']));
        self::assertStringContainsString('"status":"unavailable"', $tester->getDisplay());
    }

    private function createCommandTester(): CommandTester
    {
        return new CommandTester(new WorkerReadinessCommand(
            new WorkerReadinessProbe(
                new FrozenClock(new DateTimeImmutable('2026-07-09T10:11:12.456+00:00')),
                new ReadinessAggregator([
                    new FixedReadinessCheck(ReadinessCheckResult::ready('database_schema')),
                ]),
            ),
        ));
    }
}
