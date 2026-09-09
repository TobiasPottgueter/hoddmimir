<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quality;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use OwnedCoverageRuntimeSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function composeOwnedCoverage;
use function readOwnedCoverageManifest;
use function writeOwnedCoverageManifest;

require_once \dirname(__DIR__, 3).'/tools/compose-owned-coverage.php';

final class OwnedCoverageComposerTest extends TestCase
{
    private const string IMAGE_ID = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $temporaryDirectory;
    private string $sourceRoot;
    private string $coreReport;
    private string $databaseReport;
    private string $coreManifest;
    private string $databaseManifest;
    private string $outputReport;

    /** @var array<string, array{owner: string, coveredStatements: int, coveredBranches: int}> */
    private array $coreFiles;

    /** @var array<string, array{owner: string, coveredStatements: int, coveredBranches: int}> */
    private array $databaseFiles;

    protected function setUp(): void
    {
        $this->temporaryDirectory = \sys_get_temp_dir().'/hoddmimir-owned-coverage-'.\bin2hex(\random_bytes(8));
        $this->sourceRoot = $this->temporaryDirectory.'/src';
        self::assertTrue(\mkdir($this->sourceRoot, 0o700, true));

        foreach ([
            'Domain/Core.php',
            'Application/Proxmox/Pbs/Contract.php',
            'Infrastructure/Proxmox/Pbs/Client.php',
            'Infrastructure/Persistence/MariaDb/Store.php',
            'Kernel.php',
        ] as $relativePath) {
            $path = $this->sourceRoot.'/'.$relativePath;
            self::assertTrue(\is_dir(\dirname($path)) || \mkdir(\dirname($path), 0o700, true));
            self::assertNotFalse(\file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n"));
        }

        $this->coreReport = $this->path('core.clover.xml');
        $this->databaseReport = $this->path('database.clover.xml');
        $this->coreManifest = $this->path('core.manifest.json');
        $this->databaseManifest = $this->path('database.manifest.json');
        $this->outputReport = $this->path('combined.clover.xml');

        $this->coreFiles = [
            $this->sourceRoot.'/Application/Proxmox/Pbs/Contract.php' => $this->covered('core'),
            $this->sourceRoot.'/Domain/Core.php' => $this->covered('core'),
            $this->sourceRoot.'/Infrastructure/Proxmox/Pbs/Client.php' => $this->covered('core'),
            $this->sourceRoot.'/Kernel.php' => $this->covered('core'),
        ];
        $this->databaseFiles = [
            $this->sourceRoot.'/Infrastructure/Persistence/MariaDb/Store.php' => $this->covered('database'),
        ];

        $this->writeManifestPair();
        $this->writeReport($this->coreReport, $this->coreFiles, 100);
        $this->writeReport($this->databaseReport, $this->databaseFiles, 200);
    }

    protected function tearDown(): void
    {
        if (!\is_dir($this->temporaryDirectory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            /** @var SplFileInfo $entry */
            if ($entry->isLink() || $entry->isFile()) {
                self::assertTrue(\unlink($entry->getPathname()));
            } else {
                self::assertTrue(\rmdir($entry->getPathname()));
            }
        }
        self::assertTrue(\rmdir($this->temporaryDirectory));
    }

    public function testItComposesEveryFileFromExactlyItsOwnerAndRemainsCheckerCompatible(): void
    {
        composeOwnedCoverage(
            $this->coreReport,
            $this->databaseReport,
            $this->coreManifest,
            $this->databaseManifest,
            $this->outputReport,
            $this->sourceRoot,
        );

        $document = new DOMDocument();
        self::assertTrue($document->load($this->outputReport));
        $xpath = new DOMXPath($document);
        $files = $xpath->query('//file');
        self::assertNotFalse($files);
        self::assertSame(5, $files->length);

        $owners = [];
        foreach ($files as $file) {
            self::assertInstanceOf(DOMElement::class, $file);
            $owners[$file->getAttribute('name')] = $file->getAttribute('data-owner');
        }
        self::assertSame('database', $owners[$this->sourceRoot.'/Infrastructure/Persistence/MariaDb/Store.php']);
        self::assertSame('core', $owners[$this->sourceRoot.'/Domain/Core.php']);
        self::assertSame(200, (int) $document->documentElement?->getAttribute('generated'));

        $checker = \dirname(__DIR__, 3).'/tools/check-coverage.php';
        $lines = [];
        $exitCode = 0;
        \exec(\escapeshellarg(PHP_BINARY).' '.\escapeshellarg($checker).' '.\escapeshellarg($this->outputReport).' 2>&1', $lines, $exitCode);
        self::assertSame(0, $exitCode, \implode("\n", $lines));
    }

    public function testManifestContainsCanonicalActualHashesAndClosedRuntimeSignature(): void
    {
        $manifest = readOwnedCoverageManifest($this->coreManifest);

        self::assertSame(self::IMAGE_ID, $manifest['imageId']);
        self::assertSame(['php' => '8.5.8', 'xdebug' => '3.5.3', 'phpCodeCoverage' => '12.5.7'], $manifest['runtime']);
        self::assertSame(5, \count($manifest['files']));
        self::assertSame(
            \hash_file('sha256', $this->sourceRoot.'/Domain/Core.php'),
            $manifest['files']['src/Domain/Core.php'],
        );
        $paths = \array_keys($manifest['files']);
        $sorted = $paths;
        \sort($sorted, SORT_STRING);
        self::assertSame($sorted, $paths);
        self::assertSame((string) \file_get_contents($this->coreManifest), \file_get_contents($this->databaseManifest));
    }

    public function testItRejectsOverlappingReports(): void
    {
        $this->databaseFiles[$this->sourceRoot.'/Domain/Core.php'] = $this->covered('database');
        $this->writeReport($this->databaseReport, $this->databaseFiles, 200);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Coverage reports overlap at source file: src/Domain/Core.php');
        $this->compose();
    }

    public function testItRejectsMissingOwnedFilesWithoutCreatingOutput(): void
    {
        unset($this->coreFiles[$this->sourceRoot.'/Domain/Core.php']);
        $this->writeReport($this->coreReport, $this->coreFiles, 100);

        try {
            $this->compose();
            self::fail('A missing owned source file was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('core report is missing owned source file: src/Domain/Core.php', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->outputReport);
    }

    public function testItRejectsUnknownAndNonOwnedFiles(): void
    {
        $this->coreFiles[$this->sourceRoot.'/Unknown.php'] = $this->covered('core');
        $this->writeReport($this->coreReport, $this->coreFiles, 100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('core report contains non-owned or unknown source file: src/Unknown.php');
        $this->compose();
    }

    public function testItRejectsAFilePlacedInTheWrongOwnerWithoutOverlap(): void
    {
        $domain = $this->sourceRoot.'/Domain/Core.php';
        unset($this->coreFiles[$domain]);
        $this->databaseFiles[$domain] = $this->covered('database');
        $this->writeReport($this->coreReport, $this->coreFiles, 100);
        $this->writeReport($this->databaseReport, $this->databaseFiles, 200);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('core report is missing owned source file: src/Domain/Core.php');
        $this->compose();
    }

    public function testItRejectsADatabaseFilePlacedInTheCoreOwner(): void
    {
        $store = $this->sourceRoot.'/Infrastructure/Persistence/MariaDb/Store.php';
        unset($this->databaseFiles[$store]);
        $this->coreFiles[$store] = $this->covered('core');
        $this->writeReport($this->coreReport, $this->coreFiles, 100);
        $this->writeReport($this->databaseReport, [
            $this->sourceRoot.'/Infrastructure/Persistence/MariaDb/Other.php' => $this->covered('database'),
        ], 200);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('core report contains non-owned or unknown source file: src/Infrastructure/Persistence/MariaDb/Store.php');
        $this->compose();
    }

    public function testItRejectsDuplicateNormalizedReportPaths(): void
    {
        $duplicate = $this->fileXml('src/Domain/Core.php', $this->covered('core'));
        $xml = (string) \file_get_contents($this->coreReport);
        self::assertNotFalse(\file_put_contents($this->coreReport, \str_replace('</project>', $duplicate.'</project>', $xml)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate source file: src/Domain/Core.php');
        $this->compose();
    }

    public function testItRejectsManifestRuntimeOrImageDrift(): void
    {
        writeOwnedCoverageManifest(
            'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            $this->databaseManifest,
            $this->sourceRoot,
            $this->runtime(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('coverage manifests are not identical');
        $this->compose();
    }

    public function testItRejectsNonCanonicalOrDuplicateManifestKeys(): void
    {
        $manifest = (string) \file_get_contents($this->coreManifest);
        self::assertStringContainsString('"schema":', $manifest);
        self::assertNotFalse(\file_put_contents(
            $this->coreManifest,
            \str_replace('"schema":', "\"schema\": \"shadowed\",\n    \"schema\":", $manifest),
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not canonical or contains duplicate keys');
        readOwnedCoverageManifest($this->coreManifest);
    }

    public function testItRejectsAStaleSourceHashManifest(): void
    {
        self::assertNotFalse(\file_put_contents($this->sourceRoot.'/Domain/Core.php', "<?php\n// changed\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not match the actual src/**/*.php hashes');
        $this->compose();
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMetricMutations(): iterable
    {
        yield 'covered statements exceed denominator' => ['coveredstatements="2"', 'coveredstatements="3"'];
        yield 'covered branches exceed denominator' => ['coveredconditionals="2"', 'coveredconditionals="3"'];
        yield 'missing branch denominator' => [' conditionals="2"', ''];
        yield 'non numeric statement denominator' => ['statements="2"', 'statements="two"'];
    }

    #[DataProvider('invalidMetricMutations')]
    public function testItRejectsInvalidOrImpossibleOwnerMetrics(string $search, string $replacement): void
    {
        $xml = (string) \file_get_contents($this->coreReport);
        self::assertStringContainsString($search, $xml);
        self::assertNotFalse(\file_put_contents($this->coreReport, \str_replace($search, $replacement, $xml)));

        $this->expectException(RuntimeException::class);
        $this->compose();
    }

    public function testItRejectsExternalSourceReportEntries(): void
    {
        self::assertNotFalse(\file_put_contents(
            $this->coreReport,
            '<?xml version="1.0"?><coverage generated="1"><project><file name="/vendor/Evil.php"/></project></coverage>',
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('file outside src/');
        $this->compose();
    }

    public function testItRejectsMalformedXmlAndDocumentTypes(): void
    {
        foreach ([
            '<coverage',
            '<?xml version="1.0"?><!DOCTYPE coverage [<!ENTITY local SYSTEM "file:///etc/hosts">]><coverage generated="1"><project>&local;</project></coverage>',
        ] as $xml) {
            self::assertNotFalse(\file_put_contents($this->coreReport, $xml));
            try {
                $this->compose();
                self::fail('Unsafe Clover XML was accepted.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('not valid Clover XML', $exception->getMessage());
            }
        }
    }

    public function testItRejectsSymlinkedInputsAndOutputsWithoutTouchingSentinel(): void
    {
        $realCore = $this->path('real-core.xml');
        self::assertTrue(\rename($this->coreReport, $realCore));
        self::assertTrue(\symlink($realCore, $this->coreReport));

        try {
            $this->compose();
            self::fail('A symlinked input was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('missing, empty, unreadable, or unsafe', $exception->getMessage());
        }

        self::assertTrue(\unlink($this->coreReport));
        self::assertTrue(\rename($realCore, $this->coreReport));
        $sentinel = $this->path('sentinel.xml');
        self::assertNotFalse(\file_put_contents($sentinel, "do not replace\n"));
        self::assertTrue(\symlink($sentinel, $this->outputReport));

        try {
            $this->compose();
            self::fail('A symlinked output was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('output path is unsafe', $exception->getMessage());
        }
        self::assertSame("do not replace\n", \file_get_contents($sentinel));
    }

    public function testItRejectsSourceSymlinksAndInvalidManifestImageIds(): void
    {
        $outside = $this->path('outside.php');
        self::assertNotFalse(\file_put_contents($outside, '<?php'));
        self::assertTrue(\symlink($outside, $this->sourceRoot.'/Domain/Linked.php'));

        try {
            writeOwnedCoverageManifest(self::IMAGE_ID, $this->path('linked.json'), $this->sourceRoot, $this->runtime());
            self::fail('A symlinked source was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('source tree must not contain symlinks', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable sha256 identifier');
        writeOwnedCoverageManifest('latest', $this->path('invalid.json'), $this->sourceRoot, $this->runtime());
    }

    public function testValidationFailureDoesNotReplaceAnExistingRegularOutput(): void
    {
        self::assertNotFalse(\file_put_contents($this->outputReport, "keep current report\n"));
        unset($this->coreFiles[$this->sourceRoot.'/Domain/Core.php']);
        $this->writeReport($this->coreReport, $this->coreFiles, 100);

        try {
            $this->compose();
            self::fail('Invalid coverage inputs replaced the existing output.');
        } catch (RuntimeException) {
            self::assertSame("keep current report\n", \file_get_contents($this->outputReport));
        }
    }

    public function testCliFailsClosedForUnknownOrIncompleteCommands(): void
    {
        $tool = \dirname(__DIR__, 3).'/tools/compose-owned-coverage.php';
        $lines = [];
        $exitCode = 0;
        \exec(\escapeshellarg(PHP_BINARY).' '.\escapeshellarg($tool).' compose 2>&1', $lines, $exitCode);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Usage:', \implode("\n", $lines));
    }

    private function compose(): void
    {
        composeOwnedCoverage(
            $this->coreReport,
            $this->databaseReport,
            $this->coreManifest,
            $this->databaseManifest,
            $this->outputReport,
            $this->sourceRoot,
        );
    }

    private function writeManifestPair(): void
    {
        writeOwnedCoverageManifest(self::IMAGE_ID, $this->coreManifest, $this->sourceRoot, $this->runtime());
        writeOwnedCoverageManifest(self::IMAGE_ID, $this->databaseManifest, $this->sourceRoot, $this->runtime());
    }

    private function runtime(): OwnedCoverageRuntimeSignature
    {
        return new OwnedCoverageRuntimeSignature('8.5.8', '3.5.3', '12.5.7');
    }

    /** @return array{owner: string, coveredStatements: int, coveredBranches: int} */
    private function covered(string $owner): array
    {
        return ['owner' => $owner, 'coveredStatements' => 2, 'coveredBranches' => 2];
    }

    /** @param array<string, array{owner: string, coveredStatements: int, coveredBranches: int}> $files */
    private function writeReport(string $path, array $files, int $generated): void
    {
        $nodes = '';
        foreach ($files as $sourcePath => $metrics) {
            $nodes .= $this->fileXml($sourcePath, $metrics);
        }
        $xml = \sprintf(
            '<?xml version="1.0"?><coverage generated="%d"><project timestamp="%d">%s</project></coverage>',
            $generated,
            $generated,
            $nodes,
        );
        self::assertNotFalse(\file_put_contents($path, $xml));
    }

    /** @param array{owner: string, coveredStatements: int, coveredBranches: int} $values */
    private function fileXml(string $sourcePath, array $values): string
    {
        $coveredMethods = 0 === $values['coveredStatements'] ? 0 : 1;
        $coveredElements = $coveredMethods + $values['coveredStatements'] + $values['coveredBranches'];

        return \sprintf(
            '<file name="%s" data-owner="%s"><class name="Fixture" namespace="Fixture"><metrics complexity="1" methods="1" coveredmethods="%d" conditionals="2" coveredconditionals="%d" statements="2" coveredstatements="%d" elements="5" coveredelements="%d"/></class><metrics loc="10" ncloc="8" classes="1" methods="1" coveredmethods="%d" conditionals="2" coveredconditionals="%d" statements="2" coveredstatements="%d" elements="5" coveredelements="%d"/></file>',
            \htmlspecialchars($sourcePath, ENT_QUOTES | ENT_XML1),
            \htmlspecialchars($values['owner'], ENT_QUOTES | ENT_XML1),
            $coveredMethods,
            $values['coveredBranches'],
            $values['coveredStatements'],
            $coveredElements,
            $coveredMethods,
            $values['coveredBranches'],
            $values['coveredStatements'],
            $coveredElements,
        );
    }

    private function path(string $name): string
    {
        return $this->temporaryDirectory.'/'.$name;
    }
}
