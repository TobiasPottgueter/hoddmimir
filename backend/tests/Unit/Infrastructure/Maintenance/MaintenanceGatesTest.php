<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Maintenance;

use App\Application\Maintenance\MaintenanceAccess;
use App\Application\Maintenance\MaintenancePhase;
use App\Infrastructure\Notification\ConfiguredBackupNotificationDeliveryGate;
use App\Infrastructure\Process\EnvironmentBackupExecutionGate;
use PHPUnit\Framework\TestCase;

final class MaintenanceGatesTest extends TestCase
{
    public function testExecutionAndDeliveryPreserveDisabledConfigurationAndCloseThroughoutMaintenance(): void
    {
        foreach (MaintenancePhase::cases() as $phase) {
            foreach ([true, false] as $configured) {
                $access = $this->createStub(MaintenanceAccess::class);
                $access->method('phase')->willReturn($phase);
                $expected = $configured && MaintenancePhase::Open === $phase;
                self::assertSame($expected, (new EnvironmentBackupExecutionGate($configured, $access))->enabled());
                self::assertSame($expected, (new ConfiguredBackupNotificationDeliveryGate($configured, $access))->enabled());
            }
        }
    }
}
