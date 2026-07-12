<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quality;

use JsonException;
use PHPUnit\Framework\TestCase;

final class MutationConfigurationTest extends TestCase
{
    /** @throws JsonException */
    public function testCriticalAndGlobalMutationBucketsStayExplicit(): void
    {
        $critical = $this->configuration('infection-critical.json5.dist');
        self::assertSame(90, $critical['minMsi']);
        $criticalSource = $critical['source'] ?? null;
        self::assertIsArray($criticalSource);
        self::assertSame([
            'src/Domain',
            'src/Application/Collector',
            'src/Application/Monitoring',
            'src/Application/Inventory/Connection',
            'src/Application/Inventory/Pve',
            'src/Application/Inventory/Pbs',
            'src/Application/Inventory/Capability',
            'src/Application/Inventory/PbsContent',
        ], $criticalSource['directories']);
        self::assertArrayNotHasKey('excludes', $criticalSource);

        $global = $this->configuration('infection.json5.dist');
        self::assertSame(80, $global['minMsi']);
        $globalSource = $global['source'] ?? null;
        self::assertIsArray($globalSource);
        self::assertSame(['src'], $globalSource['directories']);
        self::assertSame(['Kernel.php'], $globalSource['excludes']);

        foreach ([$critical, $global] as $configuration) {
            self::assertSame(60, $configuration['timeout']);
            self::assertTrue($configuration['timeoutsAsEscaped']);
            self::assertSame(0, $configuration['maxTimeouts']);
            self::assertFalse($configuration['ignoreMsiWithNoMutations']);
            self::assertSame(['@default' => true], $configuration['mutators']);
        }
    }

    /** @throws JsonException */
    public function testInfectionDependencyIsPinned(): void
    {
        $composer = $this->jsonFile('composer.json');
        $developmentRequirements = $composer['require-dev'] ?? null;
        self::assertIsArray($developmentRequirements);
        self::assertSame('0.34.0', $developmentRequirements['infection/infection']);
        $config = $composer['config'] ?? null;
        self::assertIsArray($config);
        $allowedPlugins = $config['allow-plugins'] ?? null;
        self::assertIsArray($allowedPlugins);
        self::assertTrue($allowedPlugins['infection/extension-installer']);
    }

    /** @return array<string, mixed> @throws JsonException */
    private function configuration(string $name): array
    {
        return $this->jsonFile($name);
    }

    /** @return array<string, mixed> @throws JsonException */
    private function jsonFile(string $name): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$name);
        self::assertIsString($contents);
        $value = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}
