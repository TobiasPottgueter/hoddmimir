<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quality;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PveBackupReadBoundaryTest extends TestCase
{
    public function testBackupTaskSliceContainsNoWriteLogOrClusterTaskCallPath(): void
    {
        $root = dirname(__DIR__, 3).'/src/Infrastructure/Proxmox/';
        $source = '';
        foreach ([
            'PveHttpReadClient.php',
            'PveEndpointReadConnector.php',
            'PveBackupJobReader.php',
            'PveTaskPageReader.php',
            'PveTaskStatusReader.php',
        ] as $file) {
            $contents = file_get_contents($root.$file);
            if (false === $contents) {
                throw new RuntimeException('A PVE backup read boundary source file is unavailable.');
            }
            $source .= $contents;
        }

        self::assertDoesNotMatchRegularExpression('/->(?:post|put|patch|delete)\s*\(/i', $source);
        self::assertStringNotContainsString("['cluster', 'tasks']", $source);
        self::assertStringNotContainsString("'log'", $source);
        self::assertStringNotContainsString('PveHttpMethod::Post', $source);
        self::assertStringNotContainsString('PveHttpMethod::Delete', $source);
    }
}
