<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Pve;

use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveNodeStorageStatusSet;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveReadClient;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageCapacityState;
use App\Application\Proxmox\Pve\PveStorageConfigurationSet;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Proxmox\Pve\ReadPveStorageInventory;
use App\Infrastructure\Proxmox\PveClusterStatusReader;
use App\Infrastructure\Proxmox\PveJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\PveNodeStorageStatusReader;
use App\Infrastructure\Proxmox\PvePermissionReader;
use App\Infrastructure\Proxmox\PveStorageConfigurationReader;
use App\Infrastructure\Proxmox\PveVersionReader;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PveStorageFixtureContractTest extends TestCase
{
    #[DataProvider('majorProvider')]
    public function testConstructedMajorFixturesSatisfyTheStorageEnrichmentContract(
        int $major,
        string $nonBackupType,
        string $nonBackupContent,
        bool $nonBackupShared,
        ?string $namespace,
        bool $hasPruneBackups,
    ): void {
        $decoder = new PveJsonEnvelopeDecoder();
        $version = (new PveVersionReader())->read($decoder->decode($this->fixture($major, 'version')));
        $permissions = (new PvePermissionReader())->read(
            $decoder->decode($this->fixture($major, 'access-permissions')),
        );
        $topology = (new PveClusterStatusReader())->read(
            $decoder->decode($this->fixture($major, 'cluster-status-clustered')),
        );
        $configurationFixture = $this->fixture($major, 'storage-config');
        $nodeAFixture = $this->fixture($major, 'storage-node-a');
        $nodeBFixture = $this->fixture($major, 'storage-node-b');
        $configuration = (new PveStorageConfigurationReader())->read($decoder->decode($configurationFixture));
        $nodeReader = new PveNodeStorageStatusReader();
        $nodes = [
            $topology->nodes[0]->name => $nodeReader->read(
                $topology->nodes[0]->name,
                $decoder->decode($nodeAFixture),
            ),
            $topology->nodes[1]->name => $nodeReader->read(
                $topology->nodes[1]->name,
                $decoder->decode($nodeBFixture),
            ),
        ];
        $client = new FixtureStorageClient($version, $permissions, $topology, $configuration, $nodes);

        $snapshot = (new ReadPveStorageInventory(new FrozenClock(
            new DateTimeImmutable('2026-07-10T12:00:00Z'),
        )))->read($client, $permissions, $topology);

        self::assertSame($major, $version->major);
        self::assertTrue($permissions->isComplete());
        self::assertTrue($topology->isComplete());
        self::assertTrue($configuration->isContractValid());
        self::assertCount(5, $configuration->definitions);
        self::assertSame($nonBackupType, $this->definition($configuration, 'installation-media')->storageType);
        self::assertSame(
            [$nonBackupContent],
            $this->definition($configuration, 'installation-media')->content->tokens,
        );
        self::assertFalse($this->definition($configuration, 'installation-media')->supportsBackup());
        self::assertSame(
            $nonBackupShared,
            $this->definition($configuration, 'installation-media')->shared,
        );
        self::assertFalse($this->definition($configuration, 'local-backup')->shared);
        self::assertTrue($this->definition($configuration, 'disabled-backup')->disabled);
        self::assertTrue($this->definition($configuration, 'restricted-backup')->shared);
        self::assertStringNotContainsString('"maxfiles"', $configurationFixture);
        self::assertSame($hasPruneBackups, str_contains($configurationFixture, '"prune-backups"'));
        self::assertStringNotContainsString('"format"', $nodeAFixture);
        self::assertStringNotContainsString('"format"', $nodeBFixture);
        if (9 === $major) {
            self::assertStringNotContainsString('"namespace"', $configurationFixture);
        }

        $pbs = $this->definition($configuration, 'pbs-backup')->pbsMapping;
        self::assertNotNull($pbs);
        self::assertSame(8007, $pbs->port);
        self::assertSame($namespace, $pbs->namespace);

        self::assertTrue($snapshot->isAuthoritative());
        self::assertSame([], $snapshot->issues);
        self::assertCount(4, $snapshot->observations);
        $inactive = array_values(array_filter(
            $snapshot->observations,
            static fn ($observation): bool => 'restricted-backup' === $observation->storageId,
        ));
        self::assertCount(1, $inactive);
        self::assertSame(PveStorageCapacityState::Unavailable, $inactive[0]->capacityState);
        self::assertNull($inactive[0]->capacity);
        self::assertSame(2, $client->configurationReads);
        self::assertSame([$topology->nodes[0]->name, $topology->nodes[1]->name], $client->nodeReads);
    }

    /** @return iterable<string, array{int, string, string, bool, ?string, bool}> */
    public static function majorProvider(): iterable
    {
        yield 'PVE 7' => [7, 'glusterfs', 'iso', true, null, true];
        yield 'PVE 8' => [8, 'esxi', 'import', false, 'tenant-8', true];
        yield 'PVE 9' => [9, 'future-plugin', 'future-content', false, null, false];
    }

    public function testDisabledFilteredStatusCanNeverProduceAnAuthoritativeSnapshot(): void
    {
        $decoder = new PveJsonEnvelopeDecoder();
        $version = (new PveVersionReader())->read($decoder->decode($this->fixture(9, 'version')));
        $permissions = (new PvePermissionReader())->read($decoder->decode($this->fixture(9, 'access-permissions')));
        $topology = (new PveClusterStatusReader())->read(
            $decoder->decode($this->fixture(9, 'cluster-status-standalone')),
        );
        $configuration = (new PveStorageConfigurationReader())->read([[
            'storage' => 'backup',
            'type' => 'dir',
            'content' => 'backup',
            'digest' => 'digest',
        ]]);
        $node = $topology->nodes[0]->name;
        $statuses = (new PveNodeStorageStatusReader())->read($node, [[
            'storage' => 'backup',
            'type' => 'dir',
            'content' => 'backup',
            'enabled' => 0,
            'active' => 0,
            'shared' => 0,
            'total' => 0,
            'used' => 0,
            'avail' => 0,
        ]]);
        $client = new FixtureStorageClient(
            $version,
            $permissions,
            $topology,
            $configuration,
            [$node => $statuses],
        );

        $snapshot = (new ReadPveStorageInventory(new FrozenClock(
            new DateTimeImmutable('2026-07-10T12:00:00Z'),
        )))->read($client, $permissions, $topology);

        self::assertCount(1, $statuses->issues);
        self::assertSame(PveStorageIssueCode::ConfigurationStatusConflict, $statuses->issues[0]->code);
        self::assertFalse($snapshot->isAuthoritative());
        self::assertCount(1, $snapshot->issues);
        self::assertSame(PveStorageIssueCode::ConfigurationStatusConflict, $snapshot->issues[0]->code);
    }

    private function definition(
        PveStorageConfigurationSet $configuration,
        string $storageId,
    ): \App\Application\Proxmox\Pve\PveStorageConfiguration {
        foreach ($configuration->definitions as $definition) {
            if ($storageId === $definition->storageId) {
                return $definition;
            }
        }

        throw new RuntimeException('The fixture storage definition is missing.');
    }

    private function fixture(int $major, string $name): string
    {
        $path = dirname(__DIR__, 3).sprintf('/Fixtures/Proxmox/Pve/%d/%s.json', $major, $name);
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException('The sanitized PVE storage fixture is unavailable.');
        }

        return $contents;
    }
}

/** @internal */
final class FixtureStorageClient implements PveReadClient
{
    public int $configurationReads = 0;

    /** @var list<string> */
    public array $nodeReads = [];

    /** @param array<string, PveNodeStorageStatusSet> $nodes */
    public function __construct(
        private readonly PveVersion $connectedVersion,
        private readonly PvePermissionAssessment $permissionAssessment,
        private readonly PveClusterTopology $clusterTopology,
        private readonly PveStorageConfigurationSet $configuration,
        private readonly array $nodes,
    ) {
    }

    public function version(): PveVersion
    {
        return $this->connectedVersion;
    }

    public function permissions(): PvePermissionAssessment
    {
        return $this->permissionAssessment;
    }

    public function topology(): PveClusterTopology
    {
        return $this->clusterTopology;
    }

    public function resources(): PveResourceInventory
    {
        return new PveResourceInventory([], [], [], []);
    }

    public function storageConfigurations(): PveStorageConfigurationSet
    {
        ++$this->configurationReads;
        return $this->configuration;
    }

    public function nodeBackupStorages(string $node): PveNodeStorageStatusSet
    {
        $this->nodeReads[] = $node;
        return $this->nodes[$node] ?? throw new RuntimeException('The fixture node status is missing.');
    }

    public function backupJobs(): \App\Application\Proxmox\Pve\PveBackupJobInventory
    {
        throw new RuntimeException('Not used by the storage fixture contract.');
    }

    public function backupTaskPage(
        string $node,
        \App\Application\Proxmox\Pve\PveTaskQuery $query,
    ): \App\Application\Proxmox\Pve\PveTaskPage {
        throw new RuntimeException('Not used by the storage fixture contract.');
    }

    public function backupTaskStatus(
        string $node,
        \App\Application\Proxmox\Pve\PveUpid $upid,
    ): \App\Application\Proxmox\Pve\PveTaskStatus {
        throw new RuntimeException('Not used by the storage fixture contract.');
    }
}
