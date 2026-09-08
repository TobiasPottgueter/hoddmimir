<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Metrics;

use App\Application\Backup\Metrics\QueueMetricStore;
use App\Application\Backup\Metrics\QueueMetricWindow;
use App\Application\Backup\Metrics\SampleQueueMetrics;
use App\Application\Backup\Metrics\QueueMetricsCollectorCycle;
use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorCycleResult;
use App\Application\Collector\RunCollectorCycle;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueueMetricsTest extends TestCase
{
    public function testWindowsHaveBoundedResolutionAndExactElapsedTimeAcrossDst(): void
    {
        $end = new DateTimeImmutable('2026-03-30T00:00:00Z');
        foreach ([24 => 120, 168 => 900, 720 => 3600] as $hours => $width) {
            $window = new QueueMetricWindow($hours, $end);
            self::assertSame($width, $window->bucketSeconds);
            self::assertSame($hours * 3600, $end->getTimestamp() - $window->since->getTimestamp());
        }
        $this->expectException(InvalidArgumentException::class);
        new QueueMetricWindow(25, $end);
    }

    public function testCollectorSamplesBeforeInventoryAndUsesUtcGridWithoutCatchup(): void
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-08T15:31:59.999999+02:00'));
        $store = $this->createMock(QueueMetricStore::class);
        $sampled = false;
        $store->expects(self::once())->method('sample')->willReturnCallback(static function (DateTimeImmutable $slot, DateTimeImmutable $cutoff) use (&$sampled): void {
            self::assertSame('2026-09-08T13:30:00+00:00', $slot->format('c'));
            self::assertSame('2026-08-09T13:30:00+00:00', $cutoff->format('c'));
            $sampled = true;
        });
        $cycle = new CollectorActiveCycle(new CollectorLease(new CollectorWorkerId(str_repeat('a',16)), new CollectorCycleToken(str_repeat('b',16)), 1, new DateTimeImmutable('tomorrow')), new DateTimeImmutable('now'), 1);
        $result = $this->createStub(CollectorCycleResult::class);
        $inner = $this->createMock(RunCollectorCycle::class);
        $inner->expects(self::once())->method('execute')->with($cycle)->willReturnCallback(static function () use (&$sampled, $result): CollectorCycleResult {
            self::assertTrue($sampled);
            return $result;
        });
        self::assertSame($result, (new QueueMetricsCollectorCycle($inner, new SampleQueueMetrics($store, $clock)))->execute($cycle));
    }
}
