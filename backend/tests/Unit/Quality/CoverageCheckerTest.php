<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quality;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoverageCheckerTest extends TestCase
{
    public function testItPassesAtTheExactGlobalThresholdsAndReportsTheAllowlist(): void
    {
        [$exitCode, $output] = $this->runChecker([
            ['/workspace/src/Application/Core.php', 10, 10, 10, 10],
            ['/workspace/src/Presentation/Api.php', 10, 9, 10, 8],
            ['/workspace/src/Infrastructure/Proxmox/PveClient.php', 10, 10, 10, 10],
            ['/workspace/src/Kernel.php', 100, 0, 100, 0],
        ]);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Domain + Application: lines 100.00%', $output);
        self::assertStringContainsString('Global: lines 98.00% (49/50), branches 96.00% (48/50)', $output);
        self::assertStringContainsString('Explicit source allowlist: src/Kernel.php', $output);
        self::assertStringContainsString('Infrastructure/Proxmox: lines 100.00%', $output);
        self::assertStringContainsString('Application/Proxmox/Pbs: lines 100.00%', $output);
        self::assertStringContainsString('Infrastructure/Proxmox/Pbs: lines 100.00%', $output);
    }

    public function testItFailsBelowTheGlobalLineThreshold(): void
    {
        [$exitCode, $output] = $this->runChecker([
            ['/workspace/src/Application/Core.php', 10, 10, 10, 10],
            ['/workspace/src/Presentation/Api.php', 20, 9, 10, 8],
            ['/workspace/src/Infrastructure/Proxmox/PveClient.php', 10, 10, 10, 10],
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Global line coverage 81.67% is below 95.00%.', $output);
    }

    public function testItFailsBelowTheGlobalBranchThreshold(): void
    {
        [$exitCode, $output] = $this->runChecker([
            ['/workspace/src/Application/Core.php', 10, 10, 10, 10],
            ['/workspace/src/Presentation/Api.php', 10, 9, 30, 8],
            ['/workspace/src/Infrastructure/Proxmox/PveClient.php', 10, 10, 10, 10],
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Global branch coverage 68.57% is below 90.00%.', $output);
    }

    public function testItRequiresCompleteCoreCoverage(): void
    {
        [$exitCode, $output] = $this->runChecker([
            ['/workspace/src/Application/Core.php', 10, 9, 10, 9],
            ['/workspace/src/Presentation/Api.php', 10, 10, 10, 10],
            ['/workspace/src/Infrastructure/Proxmox/PveClient.php', 10, 10, 10, 10],
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Domain + Application line coverage 95.00% is below 100.00%.', $output);
        self::assertStringContainsString('Domain + Application branch coverage 95.00% is below 100.00%.', $output);
    }

    public function testItActivatesTheProxmoxGateWhenTheFirstAdapterAppears(): void
    {
        [$exitCode, $output] = $this->runChecker([
            ['/workspace/src/Application/Core.php', 10, 10, 10, 10],
            ['/workspace/src/Infrastructure/Proxmox/PveClient.php', 10, 10, 2, 1],
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Infrastructure/Proxmox branch coverage 91.67% is below 100.00%.', $output);
    }

    public function testItDoesNotSilentlyExcludeAdditionalBootstrapFiles(): void
    {
        [$exitCode, $output] = $this->runChecker([
            ['/workspace/src/Application/Core.php', 10, 10, 10, 10],
            ['/workspace/src/Bootstrap.php', 10, 0, 10, 0],
            ['/workspace/src/Infrastructure/Proxmox/PveClient.php', 10, 10, 10, 10],
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Global line coverage 80.00% is below 95.00%.', $output);
        self::assertStringContainsString('Global branch coverage 80.00% is below 90.00%.', $output);
    }

    public function testItFailsClosedWhenTheActiveProxmoxPhaseHasNoProxmoxBucket(): void
    {
        [$exitCode, $output] = $this->runChecker([
            ['/workspace/src/Application/Core.php', 10, 10, 10, 10],
            ['/workspace/src/Presentation/Api.php', 10, 10, 10, 10],
        ], false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'Infrastructure/Proxmox has no source files in the coverage report while the Proxmox phase is active.',
            $output,
        );
    }

    /**
     * @param list<array{string, int, int, int, int}> $files
     * @return array{int, string}
     */
    private function runChecker(array $files, bool $includePbsBuckets = true): array
    {
        if ($includePbsBuckets) {
            $files[] = ['/workspace/src/Application/Proxmox/Pbs/Contract.php', 10, 10, 10, 10];
            $files[] = ['/workspace/src/Infrastructure/Proxmox/Pbs/Client.php', 10, 10, 10, 10];
        }
        $reportPath = tempnam(sys_get_temp_dir(), 'hoddmimir-coverage-');

        if (false === $reportPath) {
            throw new RuntimeException('Could not create the temporary coverage report.');
        }

        $fileXml = '';

        foreach ($files as [$path, $statements, $coveredStatements, $branches, $coveredBranches]) {
            $fileXml .= sprintf(
                '<file name="%s"><metrics statements="%d" coveredstatements="%d" conditionals="%d" coveredconditionals="%d"/></file>',
                htmlspecialchars($path, ENT_QUOTES | ENT_XML1),
                $statements,
                $coveredStatements,
                $branches,
                $coveredBranches,
            );
        }

        $writtenBytes = file_put_contents(
            $reportPath,
            '<?xml version="1.0"?><coverage><project><package>'.$fileXml.'</package></project></coverage>',
        );

        if (false === $writtenBytes) {
            throw new RuntimeException('Could not write the temporary coverage report.');
        }

        $checkerPath = dirname(__DIR__, 3).'/tools/check-coverage.php';
        $outputLines = [];
        $exitCode = 0;

        try {
            exec(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg($checkerPath).' '.escapeshellarg($reportPath).' 2>&1',
                $outputLines,
                $exitCode,
            );
        } finally {
            unlink($reportPath);
        }

        return [$exitCode, implode("\n", $outputLines)];
    }
}
