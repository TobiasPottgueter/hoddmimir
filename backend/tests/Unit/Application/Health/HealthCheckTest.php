<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Health;

use App\Application\Health\HealthCheck;
use App\Application\Readiness\ReadinessAggregator;
use App\Application\Readiness\ReadinessCheckResult;
use App\Tests\Fakes\FixedReadinessCheck;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class HealthCheckTest extends TestCase
{
    public function testItReportsHealthAtTheInjectedUtcTime(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-09T09:10:11.123+00:00'));

        self::assertSame(
            [
                'status' => 'ok',
                'checkedAt' => '2026-07-09T09:10:11.123+00:00',
                'checks' => [
                    'database_schema' => ['status' => 'ready'],
                ],
            ],
            (new HealthCheck(
                $clock,
                new ReadinessAggregator([
                    new FixedReadinessCheck(ReadinessCheckResult::ready('database_schema')),
                ]),
            ))->check()->toArray(),
        );
    }

    public function testItReportsUnavailableChecks(): void
    {
        $report = (new HealthCheck(
            new FrozenClock(new DateTimeImmutable('2026-07-09T09:10:11+00:00')),
            new ReadinessAggregator([
                new FixedReadinessCheck(ReadinessCheckResult::unavailable(
                    'database_schema',
                    'migration_version_mismatch',
                )),
            ]),
        ))->check();

        self::assertFalse($report->isReady());
        self::assertSame('unavailable', $report->toArray()['status']);
    }
}
