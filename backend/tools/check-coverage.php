<?php

declare(strict_types=1);

const COVERAGE_ALLOWLIST = [
    'src/Kernel.php',
];

const PROXMOX_PHASE_ACTIVE = true;

/** @return array{statements: int, covered_statements: int, branches: int, covered_branches: int} */
function emptyCoverageMetrics(): array
{
    return [
        'statements' => 0,
        'covered_statements' => 0,
        'branches' => 0,
        'covered_branches' => 0,
    ];
}

/**
 * @param array{statements: int, covered_statements: int, branches: int, covered_branches: int} $target
 * @param array{statements: int, covered_statements: int, branches: int, covered_branches: int} $source
 */
function addCoverageMetrics(array &$target, array $source): void
{
    foreach ($target as $key => $value) {
        $target[$key] = $value + $source[$key];
    }
}

function sourceRelativePath(string $path): ?string
{
    $normalizedPath = str_replace('\\', '/', $path);
    $sourcePosition = strrpos($normalizedPath, '/src/');

    if (false === $sourcePosition) {
        return str_starts_with($normalizedPath, 'src/') ? $normalizedPath : null;
    }

    return substr($normalizedPath, $sourcePosition + 1);
}

/** @return array{statements: int, covered_statements: int, branches: int, covered_branches: int} */
function metricsFromFile(SimpleXMLElement $file, string $relativePath): array
{
    $metrics = $file->metrics;

    foreach (['statements', 'coveredstatements', 'conditionals', 'coveredconditionals'] as $attribute) {
        if (!isset($metrics[$attribute]) || 1 !== preg_match('/^[0-9]+$/D', (string) $metrics[$attribute])) {
            throw new RuntimeException(sprintf('%s has no valid %s coverage metric.', $relativePath, $attribute));
        }
    }

    $result = [
        'statements' => (int) $metrics['statements'],
        'covered_statements' => (int) $metrics['coveredstatements'],
        'branches' => (int) $metrics['conditionals'],
        'covered_branches' => (int) $metrics['coveredconditionals'],
    ];

    if (
        $result['covered_statements'] > $result['statements']
        || $result['covered_branches'] > $result['branches']
    ) {
        throw new RuntimeException(sprintf('%s contains impossible coverage totals.', $relativePath));
    }

    return $result;
}

/** @param array{statements: int, covered_statements: int, branches: int, covered_branches: int} $metrics */
function percentage(array $metrics, string $coveredKey, string $totalKey): float
{
    if (0 === $metrics[$totalKey]) {
        return 100.0;
    }

    return 100 * $metrics[$coveredKey] / $metrics[$totalKey];
}

/**
 * @param array{statements: int, covered_statements: int, branches: int, covered_branches: int} $metrics
 * @return list<string>
 */
function checkBucket(string $name, array $metrics, float $minimumLines, float $minimumBranches): array
{
    if (0 === $metrics['statements']) {
        return [sprintf('%s has no executable statements in the coverage report.', $name)];
    }

    $lineCoverage = percentage($metrics, 'covered_statements', 'statements');
    $branchCoverage = percentage($metrics, 'covered_branches', 'branches');
    $failures = [];

    if ($lineCoverage + PHP_FLOAT_EPSILON < $minimumLines) {
        $failures[] = sprintf('%s line coverage %.2f%% is below %.2f%%.', $name, $lineCoverage, $minimumLines);
    }

    if ($branchCoverage + PHP_FLOAT_EPSILON < $minimumBranches) {
        $failures[] = sprintf('%s branch coverage %.2f%% is below %.2f%%.', $name, $branchCoverage, $minimumBranches);
    }

    printf(
        "%s: lines %.2f%% (%d/%d), branches %.2f%% (%d/%d)\n",
        $name,
        $lineCoverage,
        $metrics['covered_statements'],
        $metrics['statements'],
        $branchCoverage,
        $metrics['covered_branches'],
        $metrics['branches'],
    );

    return $failures;
}

/** @param list<string> $arguments */
function runCoverageGate(array $arguments): int
{
    if (2 !== count($arguments)) {
        fwrite(STDERR, sprintf("Usage: php %s <clover.xml>\n", $arguments[0] ?? 'check-coverage.php'));

        return 2;
    }

    $reportPath = $arguments[1];

    if (!is_file($reportPath) || !is_readable($reportPath)) {
        fwrite(STDERR, sprintf("Coverage report is not readable: %s\n", $reportPath));

        return 2;
    }

    $previousLibxmlState = libxml_use_internal_errors(true);
    $report = simplexml_load_file($reportPath, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    if (false === $report) {
        fwrite(STDERR, sprintf("Coverage report is not valid XML: %s\n", $reportPath));

        return 2;
    }

    $files = $report->xpath('/coverage/project//file');

    if (false === $files || [] === $files) {
        fwrite(STDERR, "Coverage report contains no source files.\n");

        return 2;
    }

    $global = emptyCoverageMetrics();
    $core = emptyCoverageMetrics();
    $proxmox = emptyCoverageMetrics();
    $proxmoxFiles = 0;
    $pbsApplication = emptyCoverageMetrics();
    $pbsApplicationFiles = 0;
    $pbsInfrastructure = emptyCoverageMetrics();
    $pbsInfrastructureFiles = 0;
    $seenSourceFiles = 0;

    try {
        foreach ($files as $file) {
            $relativePath = sourceRelativePath((string) $file['name']);

            if (null === $relativePath) {
                continue;
            }

            ++$seenSourceFiles;

            if (in_array($relativePath, COVERAGE_ALLOWLIST, true)) {
                continue;
            }

            $metrics = metricsFromFile($file, $relativePath);
            addCoverageMetrics($global, $metrics);

            if (str_starts_with($relativePath, 'src/Domain/') || str_starts_with($relativePath, 'src/Application/')) {
                addCoverageMetrics($core, $metrics);
            }

            if (str_starts_with($relativePath, 'src/Application/Proxmox/Pbs/')) {
                ++$pbsApplicationFiles;
                addCoverageMetrics($pbsApplication, $metrics);
            }

            if (str_starts_with($relativePath, 'src/Infrastructure/Proxmox/')) {
                ++$proxmoxFiles;
                addCoverageMetrics($proxmox, $metrics);
            }

            if (str_starts_with($relativePath, 'src/Infrastructure/Proxmox/Pbs/')) {
                ++$pbsInfrastructureFiles;
                addCoverageMetrics($pbsInfrastructure, $metrics);
            }
        }
    } catch (RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");

        return 2;
    }

    if (0 === $seenSourceFiles) {
        fwrite(STDERR, "Coverage report contains no files below src/.\n");

        return 2;
    }

    printf("Explicit source allowlist: %s\n", implode(', ', COVERAGE_ALLOWLIST));

    $failures = [
        ...checkBucket('Domain + Application', $core, 100.0, 100.0),
        ...checkBucket('Global', $global, 95.0, 90.0),
    ];

    if (0 === $proxmoxFiles && PROXMOX_PHASE_ACTIVE) {
        $failures[] = 'Infrastructure/Proxmox has no source files in the coverage report while the Proxmox phase is active.';
    } elseif (0 === $proxmoxFiles) {
        echo "Infrastructure/Proxmox: not present yet; gate will activate automatically when files appear.\n";
    } else {
        $failures = [...$failures, ...checkBucket('Infrastructure/Proxmox', $proxmox, 100.0, 100.0)];
    }

    if (0 === $pbsApplicationFiles) {
        $failures[] = 'Application/Proxmox/Pbs has no source files in the coverage report.';
    } else {
        $failures = [...$failures, ...checkBucket('Application/Proxmox/Pbs', $pbsApplication, 100.0, 100.0)];
    }

    if (0 === $pbsInfrastructureFiles) {
        $failures[] = 'Infrastructure/Proxmox/Pbs has no source files in the coverage report.';
    } else {
        $failures = [...$failures, ...checkBucket('Infrastructure/Proxmox/Pbs', $pbsInfrastructure, 100.0, 100.0)];
    }

    if ([] !== $failures) {
        foreach ($failures as $failure) {
            fwrite(STDERR, "Coverage gate failed: $failure\n");
        }

        return 1;
    }

    echo "Coverage gate passed.\n";

    return 0;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runCoverageGate($argv));
}
