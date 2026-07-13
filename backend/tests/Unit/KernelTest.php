<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testProjectDirectoryDoesNotDependOnComposerMetadataBeingPresent(): void
    {
        $kernel = new Kernel('test', false);

        self::assertSame(dirname(__DIR__, 2), $kernel->getProjectDir());
    }
}
