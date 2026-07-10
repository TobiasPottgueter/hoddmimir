<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Health;

use App\Application\Health\HealthCheck;
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
            ],
            (new HealthCheck($clock))->check()->toArray(),
        );
    }
}
