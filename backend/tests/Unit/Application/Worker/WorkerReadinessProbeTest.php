<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Worker;

use App\Application\Worker\WorkerReadinessProbe;
use App\Domain\Worker\WorkerKind;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerReadinessProbeTest extends TestCase
{
    /** @return iterable<string, array{WorkerKind, string}> */
    public static function workerKinds(): iterable
    {
        yield 'collector' => [WorkerKind::Collector, 'collector'];
        yield 'backup' => [WorkerKind::Backup, 'backup'];
    }

    #[DataProvider('workerKinds')]
    public function testItReportsEachWorkerAsReady(WorkerKind $worker, string $component): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-09T10:11:12.456+00:00'));

        self::assertSame(
            [
                'component' => $component,
                'status' => 'ready',
                'checkedAt' => '2026-07-09T10:11:12.456+00:00',
            ],
            (new WorkerReadinessProbe($clock))->probe($worker)->toArray(),
        );
    }
}
