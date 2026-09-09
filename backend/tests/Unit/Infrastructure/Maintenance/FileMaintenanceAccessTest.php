<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Maintenance;

use App\Application\Maintenance\MaintenanceActivity;
use App\Application\Maintenance\MaintenancePhase;
use App\Infrastructure\Maintenance\FileMaintenanceAccess;
use PHPUnit\Framework\TestCase;

final class FileMaintenanceAccessTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/maintenance-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        touch($this->directory.'/operations.lock');
    }
    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }
    public function testConfiguredControlMustExistAndBeValid(): void
    {
        $gate = new FileMaintenanceAccess($this->directory);
        foreach (['', 'invalid', 'open'.str_repeat(' ', 40)] as $state) {
            file_put_contents($this->directory.'/state', $state);
            self::assertSame(MaintenancePhase::Frozen, $gate->phase());
            self::assertNull($gate->acquire(MaintenanceActivity::MonitorBackups));
        }
        unlink($this->directory.'/state');
        self::assertSame(MaintenancePhase::Frozen, $gate->phase());
        self::assertSame(MaintenancePhase::Frozen, (new FileMaintenanceAccess($this->directory.'/missing'))->phase());
    }
    public function testDrainingAllowsMonitoringButBlocksOtherWorkAndFrozenBlocksAllWork(): void
    {
        $gate = new FileMaintenanceAccess($this->directory);
        foreach (MaintenancePhase::cases() as $phase) {
            file_put_contents($this->directory.'/state', $phase->value."\n");
            foreach (MaintenanceActivity::cases() as $activity) {
                $permit = $gate->acquire($activity);
                self::assertSame($phase->allows($activity), null !== $permit);
                $permit?->release();
                $permit?->release();
            }
        }
    }
    public function testMaintenanceTransitionWaitsForInFlightOperationAndBlocksNewOnes(): void
    {
        file_put_contents($this->directory.'/state', "open\n");
        $gate = new FileMaintenanceAccess($this->directory);
        $permit = $gate->acquire(MaintenanceActivity::ApplicationWrite);
        self::assertNotNull($permit);
        $host = fopen($this->directory.'/operations.lock', 'r+');
        self::assertNotFalse($host);
        self::assertFalse(flock($host, LOCK_EX | LOCK_NB));
        $permit->release();
        self::assertTrue(flock($host, LOCK_EX | LOCK_NB));
        self::assertNull($gate->acquire(MaintenanceActivity::MonitorBackups));
        file_put_contents($this->directory.'/state', "draining\n");
        flock($host, LOCK_UN);
        fclose($host);
        self::assertNull($gate->acquire(MaintenanceActivity::ApplicationWrite));
        self::assertNotNull($gate->acquire(MaintenanceActivity::MonitorBackups));
    }
    public function testSymlinksAndMissingLockFailClosed(): void
    {
        file_put_contents($this->directory.'/state', "open\n");
        unlink($this->directory.'/operations.lock');
        $gate = new FileMaintenanceAccess($this->directory);
        self::assertNull($gate->acquire(MaintenanceActivity::ApplicationWrite));
        symlink($this->directory.'/state', $this->directory.'/operations.lock');
        self::assertNull($gate->acquire(MaintenanceActivity::ApplicationWrite));
    }
    public function testUnconfiguredLocalRuntimeRemainsOpen(): void
    {
        $gate = new FileMaintenanceAccess('');
        self::assertSame(MaintenancePhase::Open, $gate->phase());
        $permit = $gate->acquire(MaintenanceActivity::CollectInventory);
        self::assertNotNull($permit);
        $permit->release();
    }
}
