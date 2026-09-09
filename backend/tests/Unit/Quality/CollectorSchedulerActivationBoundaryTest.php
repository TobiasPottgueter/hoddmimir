<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quality;

use PHPUnit\Framework\TestCase;

final class CollectorSchedulerActivationBoundaryTest extends TestCase
{
    public function testCollectorCommandUsesOnlyTheDedicatedRuntimeBoundary(): void
    {
        $command = file_get_contents(__DIR__.'/../../../src/Presentation/Cli/Command/CollectorWorkerCommand.php');

        self::assertIsString($command);
        self::assertStringContainsString('CollectorWorkerRunner', $command);
        self::assertStringNotContainsString('WorkerLoop', $command);
        self::assertStringNotContainsString("addOption('interval'", $command);
    }
}
