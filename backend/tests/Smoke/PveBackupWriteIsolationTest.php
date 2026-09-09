<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Backup\Execution\BackupExecutionGate;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class PveBackupWriteIsolationTest extends TestCase
{
    public function testProductionWritesRemainExplicitlyGated(): void
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $testContainer = $kernel->getContainer()->get('test.service_container');

        self::assertInstanceOf(TestContainer::class, $testContainer);
        $gate = $testContainer->get(BackupExecutionGate::class);
        self::assertInstanceOf(BackupExecutionGate::class, $gate);
        self::assertFalse($gate->enabled());

        $kernel->shutdown();
    }
}
