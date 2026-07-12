<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Infrastructure\Proxmox\PveBackup\PveBackupClientFactory;
use App\Infrastructure\Proxmox\PveBackup\PveNativeBackupClientFactory;
use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

final class PveBackupWriteIsolationTest extends TestCase
{
    public function testDormantPveWriteFactoryIsExcludedFromTheRuntimeContainer(): void
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $testContainer = $kernel->getContainer()->get('test.service_container');

        self::assertInstanceOf(TestContainer::class, $testContainer);
        self::assertFalse($testContainer->has(PveBackupClientFactory::class));
        self::assertFalse($testContainer->has(PveNativeBackupClientFactory::class));

        $kernel->shutdown();
    }
}
