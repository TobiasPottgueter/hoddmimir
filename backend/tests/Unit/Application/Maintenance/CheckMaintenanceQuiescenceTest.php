<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Maintenance;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionScanTarget;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Maintenance\CheckMaintenanceQuiescence;
use App\Application\Maintenance\MaintenanceQuiescenceCatalog;
use App\Application\Maintenance\MaintenanceQuiescenceFailure;
use App\Application\Maintenance\MaintenanceRemoteTasks;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckMaintenanceQuiescenceTest extends TestCase
{
    #[DataProvider('boundaries')]
    public function testBothLocalBoundariesAndWholeReadFreshnessAreRequired(bool $before, bool $after, int $seconds, bool $passes): void
    {
        $catalog = $this->createStub(MaintenanceQuiescenceCatalog::class);
        $catalog->method('hasUnsettledLocalWork')->willReturnOnConsecutiveCalls($before, $after);
        $target = new ConnectionScanTarget(new ConnectionId(str_repeat('a', 16)), 1, ProxmoxProduct::Pve, []);
        $catalog->method('targets')->willReturn([$target]);
        $catalog->method('submissionNodes')->willReturn(['original-node']);
        $remote = $this->createMock(MaintenanceRemoteTasks::class);
        $remote->expects($before ? self::never() : self::once())->method('assertQuiet')->with($target, ['original-node']);
        $clock = $this->createStub(Clock::class);
        $now = new DateTimeImmutable('2026-09-07T20:00:00Z');
        $clock->method('now')->willReturnOnConsecutiveCalls($now, $now->modify(sprintf('%+d seconds', $seconds)));
        if (!$passes) $this->expectException(MaintenanceQuiescenceFailure::class);
        (new CheckMaintenanceQuiescence($catalog, $remote, $clock))->check();
        if ($passes) self::addToAssertionCount(1);
    }
    /** @return iterable<string, array{bool, bool, int, bool}> */
    public static function boundaries(): iterable
    {
        yield 'initial local work' => [true, false, 0, false];
        yield 'work raced with read' => [false, true, 0, false];
        yield 'fresh at equality' => [false, false, 120, true];
        yield 'stale whole read' => [false, false, 121, false];
        yield 'clock regressed' => [false, false, -1, false];
        yield 'quiet' => [false, false, 0, true];
    }
}
