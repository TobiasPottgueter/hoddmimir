<?php

declare(strict_types=1);

const CRITICAL_SHARDS = 3;
const GLOBAL_REST_SHARDS = 9;
const CRITICAL_DIRECTORIES = [
    'src/Domain',
    'src/Application/Collector',
    'src/Application/Monitoring',
    'src/Application/Inventory/Connection',
    'src/Application/Inventory/Pve',
    'src/Application/Inventory/Pbs',
    'src/Application/Inventory/Capability',
    'src/Application/Inventory/PbsContent',
    'src/Application/Scheduler',
];
const STATUS_KEYS = [
    'killedCount',
    'notCoveredCount',
    'escapedCount',
    'errorCount',
    'syntaxErrorCount',
    'skippedCount',
    'ignoredCount',
    'timeOutCount',
];
const OPTIONAL_STATUS_KEYS = ['killedByStaticAnalysisCount'];

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function backendRoot(): string
{
    return dirname(__DIR__);
}

/** @return list<string> */
function sourceFiles(): array
{
    $root = backendRoot();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS),
    );
    $files = [];
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        if ($file->isLink()) {
            fail('Symlinked mutation source files are not allowed.');
        }
        if (!$file->isFile() || 'php' !== $file->getExtension()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if ('src/Kernel.php' !== $relative) {
            $files[] = $relative;
        }
    }
    sort($files, SORT_STRING);
    if ([] === $files) {
        fail('The mutation source set is empty.');
    }

    return $files;
}

function isCritical(string $path): bool
{
    foreach (CRITICAL_DIRECTORIES as $directory) {
        if (str_starts_with($path, $directory.'/')) {
            return true;
        }
    }

    return false;
}

function weight(string $path): int
{
    $lines = file(backendRoot().'/'.$path, FILE_IGNORE_NEW_LINES);
    if (false === $lines) {
        fail('A mutation source file cannot be read: '.$path);
    }
    $weight = 0;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ('' !== $trimmed && !str_starts_with($trimmed, '//') && !str_starts_with($trimmed, '*')) {
            ++$weight;
        }
    }

    return max(1, $weight);
}

/** @param list<string> $files @return list<list<string>> */
function distribute(array $files, int $count): array
{
    if (count($files) < $count) {
        fail('There are fewer mutation source files than requested shards.');
    }
    usort($files, static function (string $left, string $right): int {
        $weightComparison = weight($right) <=> weight($left);
        return 0 !== $weightComparison ? $weightComparison : strcmp($left, $right);
    });
    $shards = array_fill(0, $count, []);
    $weights = array_fill(0, $count, 0);
    foreach ($files as $file) {
        $index = 0;
        for ($candidate = 1; $candidate < $count; ++$candidate) {
            if ($weights[$candidate] < $weights[$index]) {
                $index = $candidate;
            }
        }
        $shards[$index][] = $file;
        $weights[$index] += weight($file);
    }
    foreach ($shards as &$shard) {
        sort($shard, SORT_STRING);
    }

    return $shards;
}

function assertDirectory(string $directory): void
{
    if (is_link($directory)) {
        fail('Refusing to use a symlinked mutation directory.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        fail('The mutation directory cannot be created.');
    }
}

/** @param list<string> $files */
function writeManifest(string $path, array $files): void
{
    if ([] === $files) {
        fail('A mutation shard may not be empty.');
    }
    $contents = implode("\n", $files)."\n";
    if (false === file_put_contents($path, $contents)) {
        fail('A mutation manifest cannot be written.');
    }
}

function plan(string $directory): void
{
    assertDirectory($directory);
    foreach (glob($directory.'/*.txt') ?: [] as $existing) {
        if (is_link($existing) || !unlink($existing)) {
            fail('A stale mutation manifest cannot be removed safely.');
        }
    }
    $all = sourceFiles();
    $critical = array_values(array_filter($all, isCritical(...)));
    $rest = array_values(array_filter($all, static fn (string $path): bool => !isCritical($path)));
    foreach (distribute($critical, CRITICAL_SHARDS) as $index => $files) {
        writeManifest($directory.'/critical-'.$index.'.txt', $files);
    }
    foreach (distribute($rest, GLOBAL_REST_SHARDS) as $index => $files) {
        writeManifest($directory.'/global-rest-'.$index.'.txt', $files);
    }
    $metadata = [
        'format' => 1,
        'criticalShards' => CRITICAL_SHARDS,
        'globalRestShards' => GLOBAL_REST_SHARDS,
        'sourceHash' => hash('sha256', implode("\0", $all)),
        'sourceCount' => count($all),
        'criticalSourceCount' => count($critical),
    ];
    file_put_contents($directory.'/plan.json', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
    verifyPlan($directory);
}

/** @return list<string> */
function manifest(string $path): array
{
    if (is_link($path) || !is_file($path)) {
        fail('A required mutation manifest is missing or unsafe: '.$path);
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (false === $lines || [] === $lines) {
        fail('A mutation manifest is empty: '.$path);
    }
    foreach ($lines as $line) {
        if (!preg_match('#\Asrc/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.php\z#D', $line)) {
            fail('A mutation manifest contains an invalid path.');
        }
    }
    $sorted = $lines;
    sort($sorted, SORT_STRING);
    if ($sorted !== $lines || count(array_unique($lines)) !== count($lines)) {
        fail('A mutation manifest must be sorted and unique.');
    }

    return $lines;
}

/** @return array{critical: list<string>, all: list<string>} */
function verifyPlan(string $directory): array
{
    $seen = [];
    $criticalSeen = [];
    for ($index = 0; $index < CRITICAL_SHARDS; ++$index) {
        foreach (manifest($directory.'/critical-'.$index.'.txt') as $path) {
            if (!isCritical($path)) {
                fail('A non-critical file was assigned to a critical shard.');
            }
            if (isset($seen[$path])) {
                fail('A mutation source file appears in more than one shard.');
            }
            $seen[$path] = true;
            $criticalSeen[$path] = true;
        }
    }
    for ($index = 0; $index < GLOBAL_REST_SHARDS; ++$index) {
        foreach (manifest($directory.'/global-rest-'.$index.'.txt') as $path) {
            if (isCritical($path)) {
                fail('A critical file was assigned to a global-rest shard.');
            }
            if (isset($seen[$path])) {
                fail('A mutation source file appears in more than one shard.');
            }
            $seen[$path] = true;
        }
    }
    $all = sourceFiles();
    $planned = array_keys($seen);
    sort($planned, SORT_STRING);
    if ($planned !== $all) {
        fail('The mutation shard plan does not exactly cover the global source set.');
    }
    $expectedCritical = array_values(array_filter($all, isCritical(...)));
    $actualCritical = array_keys($criticalSeen);
    sort($actualCritical, SORT_STRING);
    if ($actualCritical !== $expectedCritical) {
        fail('The mutation shard plan does not exactly cover the critical source set.');
    }
    $metadata = json_decode((string) file_get_contents($directory.'/plan.json'), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($metadata)
        || 1 !== ($metadata['format'] ?? null)
        || CRITICAL_SHARDS !== ($metadata['criticalShards'] ?? null)
        || GLOBAL_REST_SHARDS !== ($metadata['globalRestShards'] ?? null)
        || count($all) !== ($metadata['sourceCount'] ?? null)
        || count($expectedCritical) !== ($metadata['criticalSourceCount'] ?? null)
        || hash('sha256', implode("\0", $all)) !== ($metadata['sourceHash'] ?? null)) {
        fail('The mutation shard metadata does not match the current source set.');
    }

    return ['critical' => $actualCritical, 'all' => $all];
}

/** @return array<string, int> */
function readStats(string $path): array
{
    if (is_link($path) || !is_file($path)) {
        fail('A mutation shard summary is missing or unsafe: '.$path);
    }
    $decoded = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $stats = is_array($decoded) ? ($decoded['stats'] ?? null) : null;
    if (!is_array($stats) || !is_int($stats['totalMutantsCount'] ?? null) || $stats['totalMutantsCount'] <= 0) {
        fail('A mutation shard summary has invalid totals.');
    }
    $result = ['totalMutantsCount' => $stats['totalMutantsCount']];
    $sum = 0;
    foreach (STATUS_KEYS as $key) {
        if (!is_int($stats[$key] ?? null) || $stats[$key] < 0) {
            fail('A mutation shard summary has an invalid status counter: '.$key);
        }
        $result[$key] = $stats[$key];
        $sum += $stats[$key];
    }
    foreach (OPTIONAL_STATUS_KEYS as $key) {
        $value = $stats[$key] ?? 0;
        if (!is_int($value) || $value < 0) {
            fail('A mutation shard summary has an invalid status counter: '.$key);
        }
        $result[$key] = $value;
        $sum += $value;
    }
    if ($sum !== $result['totalMutantsCount']) {
        fail('Mutation shard status counters do not add up to the total.');
    }
    if (0 !== $result['timeOutCount']) {
        fail('Mutation timeouts remain a hard failure.');
    }

    return $result;
}

/** @param list<array<string, int>> $parts @return array<string, int|float> */
function aggregateStats(array $parts): array
{
    if ([] === $parts) {
        fail('No mutation shard summaries were supplied.');
    }
    $sum = ['totalMutantsCount' => 0];
    foreach (STATUS_KEYS as $key) {
        $sum[$key] = 0;
    }
    foreach (OPTIONAL_STATUS_KEYS as $key) {
        $sum[$key] = 0;
    }
    foreach ($parts as $part) {
        foreach ($sum as $key => $_value) {
            $sum[$key] += $part[$key];
        }
    }
    $denominator = $sum['totalMutantsCount'] - $sum['skippedCount'] - $sum['ignoredCount'];
    if ($denominator <= 0) {
        fail('The effective mutation denominator is empty.');
    }
    $numerator = $sum['killedCount'] + $sum['killedByStaticAnalysisCount'] + $sum['errorCount'] + $sum['syntaxErrorCount'];
    $sum['effectiveMutantsCount'] = $denominator;
    $sum['detectedMutantsCount'] = $numerator;
    $sum['msi'] = round(100 * $numerator / $denominator, 2, PHP_ROUND_HALF_UP);

    return $sum;
}

function aggregate(string $planDirectory, string $reportDirectory, string $output): void
{
    verifyPlan($planDirectory);
    $critical = [];
    $global = [];
    for ($index = 0; $index < CRITICAL_SHARDS; ++$index) {
        $name = 'critical-'.$index;
        if (manifest($planDirectory.'/'.$name.'.txt') !== manifest($reportDirectory.'/'.$name.'/manifest.txt')) {
            fail('A mutation shard report does not match its planned manifest: '.$name);
        }
        $stats = readStats($reportDirectory.'/'.$name.'/summary.json');
        $critical[] = $stats;
        $global[] = $stats;
    }
    for ($index = 0; $index < GLOBAL_REST_SHARDS; ++$index) {
        $name = 'global-rest-'.$index;
        if (manifest($planDirectory.'/'.$name.'.txt') !== manifest($reportDirectory.'/'.$name.'/manifest.txt')) {
            fail('A mutation shard report does not match its planned manifest: '.$name);
        }
        $global[] = readStats($reportDirectory.'/'.$name.'/summary.json');
    }
    $criticalStats = aggregateStats($critical);
    $globalStats = aggregateStats($global);
    if (100 * $criticalStats['detectedMutantsCount'] < 90 * $criticalStats['effectiveMutantsCount']) {
        fail(sprintf('Critical mutation MSI %.2f%% is below 90%%.', $criticalStats['msi']));
    }
    if (100 * $globalStats['detectedMutantsCount'] < 80 * $globalStats['effectiveMutantsCount']) {
        fail(sprintf('Global mutation MSI %.2f%% is below 80%%.', $globalStats['msi']));
    }
    $result = ['critical' => $criticalStats, 'global' => $globalStats];
    assertDirectory(dirname($output));
    file_put_contents($output, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
    printf("Critical MSI: %.2f%% (%d/%d)\n", $criticalStats['msi'], $criticalStats['detectedMutantsCount'], $criticalStats['effectiveMutantsCount']);
    printf("Global MSI: %.2f%% (%d/%d)\n", $globalStats['msi'], $globalStats['detectedMutantsCount'], $globalStats['effectiveMutantsCount']);
}

$arguments = $_SERVER['argv'];
array_shift($arguments);
$command = array_shift($arguments);
try {
    if ('plan' === $command && 1 === count($arguments)) {
        plan($arguments[0]);
    } elseif ('verify-plan' === $command && 1 === count($arguments)) {
        verifyPlan($arguments[0]);
    } elseif ('aggregate' === $command && 3 === count($arguments)) {
        aggregate($arguments[0], $arguments[1], $arguments[2]);
    } else {
        fail('Usage: mutation-shards.php {plan|verify-plan|aggregate} ...');
    }
} catch (JsonException $exception) {
    fail('Mutation JSON is invalid: '.$exception->getMessage());
}
