<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pve;

use App\Application\Proxmox\Pve\PveBackupJobInventory;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use App\Application\Proxmox\Pve\PveNodeResource;
use App\Application\Proxmox\Pve\PveNodeStorageStatus;
use App\Application\Proxmox\Pve\PveNodeStorageStatusSet;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveReadClient;
use App\Application\Proxmox\Pve\PveReadConnector;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageCapacity;
use App\Application\Proxmox\Pve\PveStorageCapacityState;
use App\Application\Proxmox\Pve\PveStorageConfiguration;
use App\Application\Proxmox\Pve\PveStorageConfigurationSet;
use App\Application\Proxmox\Pve\PveStorageContentSet;
use App\Application\Proxmox\Pve\PveStorageInventorySnapshot;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Proxmox\Pve\ReadPveInventory;
use App\Application\Proxmox\Pve\ReadPveStorageInventory;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadPveInventoryTest extends TestCase
{
    #[DataProvider('supportedMajorProvider')]
    public function testSupportedMajorsUseOneConnectorAndTheExactCoreStorageCheckpointOrder(int $major): void
    {
        $client = new CompositeRecordingPveClient($major, ['node-b.test', 'node-a.test']);
        $connector = new CompositeRecordingPveConnector($client);

        $snapshot = (new ReadPveInventory(
            $connector,
            new ReadPveStorageInventory(new FrozenClock(new DateTimeImmutable('2026-07-11T00:00:00Z'))),
        ))->read();

        self::assertSame(1, $connector->connections);
        self::assertSame([
            'GET /version',
            'GET /access/permissions',
            'GET /cluster/status',
            'GET /cluster/resources?type=all',
            'GET /storage',
            'GET /nodes/node-a.test/storage?content=backup',
            'GET /nodes/node-b.test/storage?content=backup',
            'GET /storage',
        ], $client->calls);
        self::assertSame(['node-a.test', 'node-b.test'], $snapshot->topologyNodeNames);
        self::assertSame($major, $snapshot->core->version->major);
        self::assertTrue($snapshot->isComplete());
        self::assertTrue($snapshot->isAuthoritative());
    }

    /** @return iterable<string, array{int}> */
    public static function supportedMajorProvider(): iterable
    {
        yield 'PVE 7' => [7];
        yield 'PVE 8' => [8];
        yield 'PVE 9' => [9];
    }

    public function testFanoutOverflowKeepsCoreUsableAndPerformsNoNodeStorageCalls(): void
    {
        $client = new CompositeRecordingPveClient(9, ['node-b.test', 'node-a.test']);
        $snapshot = (new ReadPveInventory(
            new CompositeRecordingPveConnector($client),
            new ReadPveStorageInventory(
                new FrozenClock(new DateTimeImmutable('2026-07-11T00:00:00Z')),
                1,
            ),
        ))->read();

        self::assertTrue($snapshot->core->isComplete());
        self::assertFalse($snapshot->storage->isAuthoritative());
        self::assertFalse($snapshot->isComplete());
        self::assertSame([
            'GET /version',
            'GET /access/permissions',
            'GET /cluster/status',
            'GET /cluster/resources?type=all',
            'GET /storage',
            'GET /storage',
        ], $client->calls);
        self::assertSame(
            [PveStorageIssueCode::NodeFanoutExceeded],
            array_map(static fn ($issue): PveStorageIssueCode => $issue->code, $snapshot->storage->issues),
        );
    }

    #[DataProvider('invalidFanoutProvider')]
    public function testFanoutConfigurationHasAClosedBoundedRange(int $fanout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The PVE storage node fanout must be between 1 and 1024.');

        new ReadPveStorageInventory(
            new FrozenClock(new DateTimeImmutable('2026-07-11T00:00:00Z')),
            $fanout,
        );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidFanoutProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'above maximum' => [1025];
    }

    public function testCompositeDtoRejectsNonCanonicalTopologyNames(): void
    {
        $client = new CompositeRecordingPveClient(9, ['node-a.test']);
        $valid = (new ReadPveInventory(
            new CompositeRecordingPveConnector($client),
            new ReadPveStorageInventory(new FrozenClock(new DateTimeImmutable('2026-07-11T00:00:00Z'))),
        ))->read();

        foreach ([
            ['node-b.test', 'node-a.test'],
            ['node-a.test', 'node-a.test'],
            [''],
            ['different.test'],
        ] as $invalid) {
            try {
                new PveInventorySnapshot($valid->core, $valid->storage, $invalid);
                self::fail('A non-canonical topology node list was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $partialStorage = new PveStorageInventorySnapshot(null, null, [], []);
        $partial = new PveInventorySnapshot($valid->core, $partialStorage, ['node-a.test']);
        self::assertFalse($partial->isComplete());
        self::assertFalse($partial->isAuthoritative());
    }
}

/** @internal */
final class CompositeRecordingPveConnector implements PveReadConnector
{
    public int $connections = 0;

    public function __construct(private readonly CompositeRecordingPveClient $client)
    {
    }

    public function connect(): PveReadClient
    {
        ++$this->connections;

        return $this->client;
    }
}

/** @internal */
final class CompositeRecordingPveClient implements PveReadClient
{
    /** @var list<string> */
    public array $calls = [];

    /** @param non-empty-list<string> $nodes */
    public function __construct(
        private readonly int $major,
        private readonly array $nodes,
    ) {
    }

    public function version(): PveVersion
    {
        $this->calls[] = 'GET /version';

        return new PveVersion($this->major, 4, 1, $this->major.'.4', $this->major.'.4.1', 'repo');
    }

    public function permissions(): PvePermissionAssessment
    {
        $this->calls[] = 'GET /access/permissions';

        return new PvePermissionAssessment([]);
    }

    public function topology(): PveClusterTopology
    {
        $this->calls[] = 'GET /cluster/status';
        $members = [];
        foreach ($this->nodes as $index => $node) {
            $members[] = new PveClusterNode($node, true, $index + 1, 0 === $index);
        }

        return new PveClusterTopology(
            PveClusterMode::Clustered,
            'forest',
            count($members),
            1,
            true,
            $members,
            [],
        );
    }

    public function resources(): PveResourceInventory
    {
        $this->calls[] = 'GET /cluster/resources?type=all';
        $nodes = array_map(
            static fn (string $node): PveNodeResource => new PveNodeResource($node, 'online'),
            $this->nodes,
        );

        return new PveResourceInventory($nodes, [], [], []);
    }

    public function storageConfigurations(): PveStorageConfigurationSet
    {
        $this->calls[] = 'GET /storage';

        return new PveStorageConfigurationSet('digest', [new PveStorageConfiguration(
            'backup',
            'pbs',
            new PveStorageContentSet(['backup']),
            null,
            false,
            true,
            null,
        )], []);
    }

    public function nodeBackupStorages(string $node): PveNodeStorageStatusSet
    {
        $this->calls[] = sprintf('GET /nodes/%s/storage?content=backup', $node);

        return new PveNodeStorageStatusSet($node, [new PveNodeStorageStatus(
            $node,
            'backup',
            'pbs',
            new PveStorageContentSet(['backup']),
            true,
            true,
            true,
            PveStorageCapacityState::Fresh,
            new PveStorageCapacity(1000, 250, 700),
        )], []);
    }

    public function backupJobs(): PveBackupJobInventory
    {
        throw new LogicException('Not used by this composite read.');
    }

    public function backupTaskPage(string $node, PveTaskQuery $query): PveTaskPage
    {
        throw new LogicException('Not used by this composite read.');
    }

    public function backupTaskStatus(string $node, PveUpid $upid): PveTaskStatus
    {
        throw new LogicException('Not used by this composite read.');
    }
}
