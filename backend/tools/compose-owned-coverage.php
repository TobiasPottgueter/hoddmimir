<?php

declare(strict_types=1);

use Composer\InstalledVersions;

require_once dirname(__DIR__).'/vendor/autoload.php';

const OWNED_COVERAGE_MANIFEST_SCHEMA = 'hoddmimir-owned-coverage-manifest-v1';
const OWNED_COVERAGE_DATABASE_PREFIX = 'src/Infrastructure/Persistence/MariaDb/';

final readonly class OwnedCoverageRuntimeSignature
{
    public function __construct(
        public string $php,
        public string $xdebug,
        public string $phpCodeCoverage,
    ) {
        foreach ([$php, $xdebug, $phpCodeCoverage] as $version) {
            if (1 !== \preg_match('/\A[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}\z/D', $version)) {
                throw new RuntimeException('Coverage runtime versions must be non-empty, printable identifiers.');
            }
        }
    }

    /** @return array{php: string, xdebug: string, phpCodeCoverage: string} */
    public function toArray(): array
    {
        return [
            'php' => $this->php,
            'xdebug' => $this->xdebug,
            'phpCodeCoverage' => $this->phpCodeCoverage,
        ];
    }
}

/** @return array<string, string> */
function ownedCoverageSourceHashes(string $sourceRoot): array
{
    if (!\is_dir($sourceRoot) || \is_link($sourceRoot)) {
        throw new RuntimeException("Coverage source root is not a safe directory: $sourceRoot");
    }

    $root = \realpath($sourceRoot);
    if (false === $root) {
        throw new RuntimeException("Coverage source root cannot be resolved: $sourceRoot");
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isLink()) {
            throw new RuntimeException('Coverage source tree must not contain symlinks: '.$entry->getPathname());
        }
        if (!$entry->isFile() || 'php' !== \strtolower($entry->getExtension())) {
            continue;
        }

        $relative = \str_replace('\\', '/', \substr($entry->getPathname(), \strlen($root) + 1));
        $path = 'src/'.$relative;
        $hash = \hash_file('sha256', $entry->getPathname());
        if (false === $hash) {
            throw new RuntimeException("Could not hash coverage source file: $path");
        }
        if (isset($files[$path])) {
            throw new RuntimeException("Coverage source file appears more than once: $path");
        }

        $files[$path] = $hash;
    }

    \ksort($files, SORT_STRING);
    if ([] === $files) {
        throw new RuntimeException('Coverage source tree contains no PHP files.');
    }

    return $files;
}

function writeOwnedCoverageManifest(
    string $imageId,
    string $outputPath,
    string $sourceRoot,
    OwnedCoverageRuntimeSignature $runtime,
): void {
    if (1 !== \preg_match('/\Asha256:[0-9a-f]{64}\z/D', $imageId)) {
        throw new RuntimeException('Coverage image ID must be an immutable sha256 identifier.');
    }

    $manifest = [
        'schema' => OWNED_COVERAGE_MANIFEST_SCHEMA,
        'imageId' => $imageId,
        'runtime' => $runtime->toArray(),
        'files' => ownedCoverageSourceHashes($sourceRoot),
    ];
    $json = \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    writeOwnedCoverageArtifact($outputPath, $json, []);
}

/**
 * @return array{
 *   schema: string,
 *   imageId: string,
 *   runtime: array{php: string, xdebug: string, phpCodeCoverage: string},
 *   files: array<string, string>
 * }
 */
function readOwnedCoverageManifest(string $path): array
{
    assertOwnedCoverageInputFile($path, 'Coverage manifest');
    $contents = \file_get_contents($path);
    if (false === $contents) {
        throw new RuntimeException("Could not read coverage manifest: $path");
    }

    try {
        $manifest = \json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("Coverage manifest is not valid JSON: $path", 0, $exception);
    }

    if (!\is_array($manifest) || ['schema', 'imageId', 'runtime', 'files'] !== \array_keys($manifest)
        || OWNED_COVERAGE_MANIFEST_SCHEMA !== ($manifest['schema'] ?? null)
        || !\is_string($manifest['imageId'] ?? null)
        || 1 !== \preg_match('/\Asha256:[0-9a-f]{64}\z/D', $manifest['imageId'])
        || !\is_array($manifest['runtime'] ?? null)
        || ['php', 'xdebug', 'phpCodeCoverage'] !== \array_keys($manifest['runtime'])
        || !\is_array($manifest['files'] ?? null)) {
        throw new RuntimeException("Coverage manifest has an invalid closed schema: $path");
    }

    $phpVersion = $manifest['runtime']['php'] ?? null;
    $xdebugVersion = $manifest['runtime']['xdebug'] ?? null;
    $phpCodeCoverageVersion = $manifest['runtime']['phpCodeCoverage'] ?? null;
    if (!\is_string($phpVersion) || !\is_string($xdebugVersion) || !\is_string($phpCodeCoverageVersion)
        || 1 !== \preg_match('/\A[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}\z/D', $phpVersion)
        || 1 !== \preg_match('/\A[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}\z/D', $xdebugVersion)
        || 1 !== \preg_match('/\A[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}\z/D', $phpCodeCoverageVersion)) {
        throw new RuntimeException("Coverage manifest has an invalid runtime signature: $path");
    }

    $files = [];
    foreach ($manifest['files'] as $sourcePath => $hash) {
        if (!\is_string($sourcePath) || !isOwnedCoverageSourcePath($sourcePath)
            || !\is_string($hash) || 1 !== \preg_match('/\A[0-9a-f]{64}\z/D', $hash)
            || isset($files[$sourcePath])) {
            throw new RuntimeException("Coverage manifest has an invalid source hash entry: $path");
        }
        $files[$sourcePath] = $hash;
    }
    if ([] === $files || \array_keys($files) !== (static function (array $paths): array {
        \sort($paths, SORT_STRING);

        return $paths;
    })(\array_keys($files))) {
        throw new RuntimeException("Coverage manifest source hashes are empty or not canonically sorted: $path");
    }

    $result = [
        'schema' => $manifest['schema'],
        'imageId' => $manifest['imageId'],
        'runtime' => [
            'php' => $phpVersion,
            'xdebug' => $xdebugVersion,
            'phpCodeCoverage' => $phpCodeCoverageVersion,
        ],
        'files' => $files,
    ];
    $canonical = \json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (!\hash_equals($canonical, $contents)) {
        throw new RuntimeException("Coverage manifest is not canonical or contains duplicate keys: $path");
    }

    return $result;
}

/**
 * @return array{
 *   document: DOMDocument,
 *   generated: int,
 *   files: array<string, DOMElement>,
 *   metrics: array<string, array<string, int>>
 * }
 */
function readOwnedCoverageReport(string $path): array
{
    assertOwnedCoverageInputFile($path, 'Clover report');

    $previousLibxmlState = \libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->resolveExternals = false;
    $document->substituteEntities = false;
    $loaded = $document->load($path, LIBXML_NONET | LIBXML_NOBLANKS);
    \libxml_clear_errors();
    \libxml_use_internal_errors($previousLibxmlState);

    if (!$loaded || null !== $document->doctype || 'coverage' !== $document->documentElement?->tagName) {
        throw new RuntimeException("Coverage report is not valid Clover XML: $path");
    }
    $generated = $document->documentElement->getAttribute('generated');
    if (1 !== \preg_match('/\A[0-9]+\z/D', $generated)) {
        throw new RuntimeException("Coverage report has no valid generation timestamp: $path");
    }

    $projects = $document->getElementsByTagName('project');
    if (1 !== $projects->length) {
        throw new RuntimeException("Coverage report must contain exactly one project: $path");
    }

    $files = [];
    $metrics = [];
    foreach ($document->getElementsByTagName('file') as $fileNode) {
        $relativePath = ownedCoverageRelativePath($fileNode->getAttribute('name'));
        if (null === $relativePath) {
            throw new RuntimeException('Coverage report contains a file outside src/: '.$fileNode->getAttribute('name'));
        }
        if (isset($files[$relativePath])) {
            throw new RuntimeException("Coverage report contains a duplicate source file: $relativePath");
        }

        $fileMetrics = null;
        foreach ($fileNode->childNodes as $child) {
            if ($child instanceof DOMElement && 'metrics' === $child->tagName) {
                if (null !== $fileMetrics) {
                    throw new RuntimeException("Coverage source file has duplicate metrics: $relativePath");
                }
                $fileMetrics = ownedCoverageMetrics($child, $relativePath);
            }
        }
        if (null === $fileMetrics) {
            throw new RuntimeException("Coverage source file has no direct metrics: $relativePath");
        }

        $files[$relativePath] = $fileNode;
        $metrics[$relativePath] = $fileMetrics;
    }
    \ksort($files, SORT_STRING);
    \ksort($metrics, SORT_STRING);
    if ([] === $files) {
        throw new RuntimeException("Coverage report contains no source files: $path");
    }

    return [
        'document' => $document,
        'generated' => (int) $generated,
        'files' => $files,
        'metrics' => $metrics,
    ];
}

/** @return array<string, int> */
function ownedCoverageMetrics(DOMElement $metrics, string $relativePath): array
{
    $required = [
        'loc', 'ncloc', 'classes', 'methods', 'coveredmethods', 'conditionals',
        'coveredconditionals', 'statements', 'coveredstatements', 'elements', 'coveredelements',
    ];
    $result = [];
    foreach ($required as $attribute) {
        $value = $metrics->getAttribute($attribute);
        if (1 !== \preg_match('/\A[0-9]+\z/D', $value)) {
            throw new RuntimeException("$relativePath has no valid $attribute coverage metric.");
        }
        $result[$attribute] = (int) $value;
    }
    foreach ([
        'coveredmethods' => 'methods',
        'coveredconditionals' => 'conditionals',
        'coveredstatements' => 'statements',
        'coveredelements' => 'elements',
    ] as $covered => $total) {
        if ($result[$covered] > $result[$total]) {
            throw new RuntimeException("$relativePath contains impossible $covered coverage totals.");
        }
    }

    return $result;
}

function composeOwnedCoverage(
    string $coreReportPath,
    string $databaseReportPath,
    string $coreManifestPath,
    string $databaseManifestPath,
    string $outputPath,
    string $sourceRoot,
): void {
    $protectedInputs = [$coreReportPath, $databaseReportPath, $coreManifestPath, $databaseManifestPath];
    assertDistinctOwnedCoveragePaths($protectedInputs);

    $coreManifest = readOwnedCoverageManifest($coreManifestPath);
    $databaseManifest = readOwnedCoverageManifest($databaseManifestPath);
    $coreManifestBytes = \file_get_contents($coreManifestPath);
    $databaseManifestBytes = \file_get_contents($databaseManifestPath);
    if (!\is_string($coreManifestBytes) || !\is_string($databaseManifestBytes)
        || !\hash_equals($coreManifestBytes, $databaseManifestBytes)
        || $coreManifest !== $databaseManifest) {
        throw new RuntimeException('Core and database coverage manifests are not identical.');
    }

    $actualHashes = ownedCoverageSourceHashes($sourceRoot);
    if ($coreManifest['files'] !== $actualHashes) {
        throw new RuntimeException('Coverage manifests do not match the actual src/**/*.php hashes.');
    }

    $core = readOwnedCoverageReport($coreReportPath);
    $database = readOwnedCoverageReport($databaseReportPath);
    $corePaths = \array_keys($core['files']);
    $databasePaths = \array_keys($database['files']);
    $overlap = \array_values(\array_intersect($corePaths, $databasePaths));
    if ([] !== $overlap) {
        throw new RuntimeException('Coverage reports overlap at source file: '.$overlap[0]);
    }

    $expectedCore = [];
    $expectedDatabase = [];
    foreach (\array_keys($actualHashes) as $sourcePath) {
        if (\str_starts_with($sourcePath, OWNED_COVERAGE_DATABASE_PREFIX)) {
            $expectedDatabase[] = $sourcePath;
        } else {
            $expectedCore[] = $sourcePath;
        }
    }
    if ([] === $expectedCore || [] === $expectedDatabase) {
        throw new RuntimeException('Coverage ownership requires non-empty core and database source buckets.');
    }
    assertOwnedCoverageSet($corePaths, $expectedCore, 'core');
    assertOwnedCoverageSet($databasePaths, $expectedDatabase, 'database');

    $orderedOwnerNodes = [];
    $orderedMetrics = [];
    foreach (\array_keys($actualHashes) as $sourcePath) {
        if (\str_starts_with($sourcePath, OWNED_COVERAGE_DATABASE_PREFIX)) {
            $orderedOwnerNodes[$sourcePath] = $database['files'][$sourcePath];
            $orderedMetrics[$sourcePath] = $database['metrics'][$sourcePath];
        } else {
            $orderedOwnerNodes[$sourcePath] = $core['files'][$sourcePath];
            $orderedMetrics[$sourcePath] = $core['metrics'][$sourcePath];
        }
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $coverage = $document->createElement('coverage');
    $generated = \max($core['generated'], $database['generated']);
    $coverage->setAttribute('generated', (string) $generated);
    $document->appendChild($coverage);
    $project = $document->createElement('project');
    $project->setAttribute('name', 'Hoddmimir owned backend coverage');
    $project->setAttribute('timestamp', (string) $generated);
    $project->setAttribute('source-manifest-sha256', \hash('sha256', $coreManifestBytes));
    $coverage->appendChild($project);

    foreach ($orderedOwnerNodes as $fileNode) {
        $project->appendChild($document->importNode($fileNode, true));
    }

    $projectMetrics = ['files' => \count($orderedMetrics)];
    foreach ($orderedMetrics as $fileMetrics) {
        foreach ($fileMetrics as $name => $value) {
            $projectMetrics[$name] = ($projectMetrics[$name] ?? 0) + $value;
        }
    }
    $metrics = $document->createElement('metrics');
    foreach ($projectMetrics as $name => $value) {
        $metrics->setAttribute($name, (string) $value);
    }
    $project->appendChild($metrics);

    $xml = $document->saveXML();
    if (false === $xml) {
        throw new RuntimeException('Could not serialize the owned Clover report.');
    }
    writeOwnedCoverageArtifact($outputPath, $xml, $protectedInputs);
}

/**
 * @param list<string> $actual
 * @param list<string> $expected
 */
function assertOwnedCoverageSet(array $actual, array $expected, string $owner): void
{
    \sort($actual, SORT_STRING);
    \sort($expected, SORT_STRING);
    if ($actual === $expected) {
        return;
    }

    $missing = \array_values(\array_diff($expected, $actual));
    if ([] !== $missing) {
        throw new RuntimeException("Coverage $owner report is missing owned source file: ".$missing[0]);
    }
    $unexpected = \array_values(\array_diff($actual, $expected));
    throw new RuntimeException("Coverage $owner report contains non-owned or unknown source file: ".$unexpected[0]);
}

function ownedCoverageRelativePath(string $path): ?string
{
    $normalized = \str_replace('\\', '/', $path);
    $position = \strrpos($normalized, '/src/');
    $relative = false === $position
        ? (\str_starts_with($normalized, 'src/') ? $normalized : null)
        : \substr($normalized, $position + 1);

    return null !== $relative && isOwnedCoverageSourcePath($relative) ? $relative : null;
}

function isOwnedCoverageSourcePath(string $path): bool
{
    if (1 !== \preg_match('/\Asrc\/[A-Za-z0-9_.\/-]+\.php\z/D', $path)) {
        return false;
    }

    foreach (\explode('/', \substr($path, 4)) as $segment) {
        if ('' === $segment || '.' === $segment || '..' === $segment) {
            return false;
        }
    }

    return true;
}

function assertOwnedCoverageInputFile(string $path, string $label): void
{
    if ('' === $path || !\is_file($path) || \is_link($path) || !\is_readable($path) || 0 === \filesize($path)) {
        throw new RuntimeException("$label is missing, empty, unreadable, or unsafe: $path");
    }
}

/** @param list<string> $paths */
function assertDistinctOwnedCoveragePaths(array $paths): void
{
    $resolved = [];
    foreach ($paths as $path) {
        assertOwnedCoverageInputFile($path, 'Coverage input');
        $real = \realpath($path);
        if (false === $real || isset($resolved[$real])) {
            throw new RuntimeException('Coverage inputs must be distinct regular files.');
        }
        $resolved[$real] = true;
    }
}

/** @param list<string> $protectedInputs */
function writeOwnedCoverageArtifact(string $path, string $contents, array $protectedInputs): void
{
    if ('' === $path || \is_link($path) || (\file_exists($path) && !\is_file($path))) {
        throw new RuntimeException("Coverage output path is unsafe: $path");
    }
    $directory = \realpath(\dirname($path));
    if (false === $directory || !\is_dir($directory) || \is_link(\dirname($path))) {
        throw new RuntimeException("Coverage output directory is unsafe: $path");
    }
    $target = $directory.'/'.\basename($path);
    foreach ($protectedInputs as $input) {
        if (\realpath($input) === $target) {
            throw new RuntimeException('Coverage output must not replace an input artifact.');
        }
    }

    $temporary = \tempnam($directory, '.owned-coverage-');
    if (false === $temporary) {
        throw new RuntimeException("Could not create temporary coverage output beside: $path");
    }
    try {
        $written = \file_put_contents($temporary, $contents, LOCK_EX);
        if (false === $written || $written !== \strlen($contents) || !\rename($temporary, $target)) {
            throw new RuntimeException("Could not atomically write coverage output: $path");
        }
    } finally {
        if (\file_exists($temporary)) {
            \unlink($temporary);
        }
    }
}

/** @param list<string> $arguments */
function runOwnedCoverageTool(array $arguments): int
{
    try {
        $command = $arguments[1] ?? null;
        if ('manifest' === $command && 4 === \count($arguments)) {
            $xdebugVersion = \phpversion('xdebug');
            $coverageVersion = InstalledVersions::getPrettyVersion('phpunit/php-code-coverage');
            if (!\is_string($xdebugVersion) || '' === $xdebugVersion
                || !\is_string($coverageVersion) || '' === $coverageVersion) {
                throw new RuntimeException('Coverage manifest generation requires Xdebug and phpunit/php-code-coverage.');
            }
            writeOwnedCoverageManifest(
                $arguments[2],
                $arguments[3],
                dirname(__DIR__).'/src',
                new OwnedCoverageRuntimeSignature(PHP_VERSION, $xdebugVersion, $coverageVersion),
            );
            echo "Wrote owned coverage source manifest to {$arguments[3]}.\n";

            return 0;
        }
        if ('compose' === $command && 7 === \count($arguments)) {
            composeOwnedCoverage(
                $arguments[2],
                $arguments[3],
                $arguments[4],
                $arguments[5],
                $arguments[6],
                dirname(__DIR__).'/src',
            );
            echo "Composed source-owned Clover report into {$arguments[6]}.\n";

            return 0;
        }

        \fwrite(STDERR, "Usage:\n");
        \fwrite(STDERR, "  php tools/compose-owned-coverage.php manifest <image-id> <output.json>\n");
        \fwrite(STDERR, "  php tools/compose-owned-coverage.php compose <core.clover> <db.clover> <core-manifest> <db-manifest> <output.clover>\n");

        return 2;
    } catch (Throwable $exception) {
        \fwrite(STDERR, $exception->getMessage()."\n");

        return 2;
    }
}

if (\is_string($_SERVER['SCRIPT_FILENAME'] ?? null) && \realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $rawArguments = $_SERVER['argv'] ?? [];
    $arguments = [];
    if (\is_array($rawArguments)) {
        foreach ($rawArguments as $argument) {
            if (\is_string($argument)) {
                $arguments[] = $argument;
            }
        }
    }
    exit(runOwnedCoverageTool($arguments));
}
