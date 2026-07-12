<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\PbsContent;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorShutdownRequested;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\PbsContent\PbsContentApplyResult;
use App\Application\Inventory\PbsContent\PbsContentCommit;
use App\Application\Inventory\PbsContent\PbsContentRunFailure;
use App\Application\Inventory\PbsContent\PbsContentRunStart;
use App\Application\Inventory\PbsContent\PbsContentRunStatus;
use App\Application\Inventory\PbsContent\PbsContentScopeResult;
use App\Application\Inventory\PbsContent\PbsContentScopeStatus;
use App\Application\Inventory\PbsContent\PbsContentScopeType;
use App\Application\Inventory\PbsContent\PbsContentSnapshot;
use App\Application\Inventory\PbsContent\PbsContentStore;
use App\Application\Inventory\PbsContent\PbsNamespaceObservation;
use App\Application\Inventory\PbsContent\RunSelectedEndpointPbsContent;
use App\Application\Inventory\PbsContent\SelectedEndpointPbsContentReader;
use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreCapacity;
use App\Application\Proxmox\Pbs\PbsDatastoreConfigurationSnapshot;
use App\Application\Proxmox\Pbs\PbsDatastoreDefinition;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveNodeResource;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveVersion;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RunSelectedEndpointPbsContentTest extends TestCase
{
    public function testPersistsContentFromTheAlreadySelectedEndpoint(): void
    {
        [$runner, $reader, $store, $checkpoint] = $this->runner();

        $status = $runner->execute($this->lease(), self::id('parent'), $this->pbsRead(), $checkpoint);

        self::assertSame(PbsContentRunStatus::Succeeded, $status);
        self::assertSame(1, $reader->reads);
        self::assertSame(['store_a'], array_map(static fn (PbsDatastoreId $id): string => $id->value, $reader->datastores));
        self::assertSame(self::bytes('endpoint'), $reader->endpointId?->bytes);
        self::assertSame(7, $reader->revision);
        self::assertCount(1, $store->begun);
        self::assertCount(1, $store->applied);
        self::assertSame([], $store->failed);
        self::assertSame(3, $checkpoint->calls);
    }

    public function testInstallationWithoutDatastoresCompletesAsSuccessfulNoop(): void
    {
        [$runner, $reader, $store, $checkpoint] = $this->runner(snapshot: new PbsContentSnapshot([], [], []));

        $status = $runner->execute(
            $this->lease(),
            self::id('parent'),
            $this->pbsReadWithoutDatastores(),
            $checkpoint,
        );

        self::assertSame(PbsContentRunStatus::Succeeded, $status);
        self::assertSame([], $reader->datastores);
        self::assertCount(1, $store->begun);
        self::assertCount(1, $store->applied);
        self::assertSame([], $store->applied[0]->snapshot->scopes);
        self::assertSame([], $store->failed);
    }

    public function testWrongProductIsRejectedBeforeStartingAChildRun(): void
    {
        [$runner, $reader, $store, $checkpoint] = $this->runner();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $runner->execute($this->lease(), self::id('parent'), $this->pveRead(), $checkpoint);
        } finally {
            self::assertSame(0, $reader->reads);
            self::assertSame([], $store->begun);
            self::assertSame(0, $checkpoint->calls);
        }
    }

    public function testOrdinaryFailuresAreTerminalizedAndRemainFailed(): void
    {
        foreach (['begin', 'read', 'apply'] as $stage) {
            [$runner, , $store, $checkpoint] = $this->runner(failAt: $stage);
            self::assertSame(
                PbsContentRunStatus::Failed,
                $runner->execute($this->lease(), self::id('parent'), $this->pbsRead(), $checkpoint),
            );
            self::assertSame(['pbs_content_failed'], array_column($store->failed, 'errorCode'));
        }

        [$runner, , $store, $checkpoint] = $this->runner(failAt: 'read', failTerminalization: true);
        self::assertSame(
            PbsContentRunStatus::Failed,
            $runner->execute($this->lease(), self::id('parent'), $this->pbsRead(), $checkpoint),
        );
        self::assertSame([], $store->failed);
    }

    public function testLeaseLossAndShutdownAreTerminalizedAndRethrown(): void
    {
        $cases = [
            [new CollectorLeaseOwnershipLost('lost'), 'collector_lease_lost'],
            [new CollectorShutdownRequested(new CollectorActiveCycle($this->lease(), new DateTimeImmutable('2026-07-12T00:00:00Z'), 1)), 'collector_shutdown_requested'],
        ];
        foreach ($cases as [$failure, $expectedCode]) {
            [$runner, , $store, $checkpoint] = $this->runner(failure: $failure);
            try {
                $runner->execute($this->lease(), self::id('parent'), $this->pbsRead(), $checkpoint);
                self::fail('A critical collector signal was swallowed.');
            } catch (CollectorLeaseOwnershipLost|CollectorShutdownRequested $caught) {
                self::assertSame($failure, $caught);
                self::assertSame([$expectedCode], array_column($store->failed, 'errorCode'));
            }
        }
    }

    /** @return array{RunSelectedEndpointPbsContent, RecordingContentReader, RecordingContentStore, RecordingContentCheckpoint} */
    private function runner(
        ?string $failAt = null,
        ?\Throwable $failure = null,
        bool $failTerminalization = false,
        ?PbsContentSnapshot $snapshot = null,
    ): array {
        $reader = new RecordingContentReader(
            $snapshot ?? $this->snapshot(),
            'read' === $failAt ? new \RuntimeException('read') : $failure,
        );
        $store = new RecordingContentStore($failAt, $failure, $failTerminalization);
        $checkpoint = new RecordingContentCheckpoint();
        return [
            new RunSelectedEndpointPbsContent($reader, $store, new SequentialContentIds(), new ContentFixedClock()),
            $reader,
            $store,
            $checkpoint,
        ];
    }

    private function snapshot(): PbsContentSnapshot
    {
        $store = new PbsDatastoreId('store_a');
        return new PbsContentSnapshot(
            [new PbsNamespaceObservation($store, PbsNamespace::root())],
            [],
            [new PbsContentScopeResult(PbsContentScopeType::Namespaces, $store, null, PbsContentScopeStatus::Complete, 1)],
        );
    }

    private function pbsRead(): ConnectionInstallationRead
    {
        $endpoint = new EndpointId(self::bytes('endpoint'));
        $store = new PbsDatastoreId('store_a');
        $configuration = new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), [$store]);
        return new ConnectionInstallationRead(
            new ConnectionId(self::bytes('connection')),
            7,
            $endpoint,
            InstallationBinding::pbsLegacyNode('pbs-a', $endpoint),
            new PbsInstallationSnapshot(
                new PbsVersion(3, 4, 4, '3.4.4', '1', 'repo'),
                'pbs-a',
                new PbsNodeStatus('pbs-a', 1, 100, 20, 100, 20, 80),
                null,
                PbsDatastoreScanScope::installationWide(),
                $configuration,
                $configuration,
                [new PbsDatastoreDefinition($store, PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null)],
                [new PbsDatastoreCapacity($store, PbsDatastoreBackendType::Filesystem, 100, 20, 80)],
                [],
            ),
        );
    }

    private function pbsReadWithoutDatastores(): ConnectionInstallationRead
    {
        $endpoint = new EndpointId(self::bytes('endpoint'));
        $configuration = new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), []);

        return new ConnectionInstallationRead(
            new ConnectionId(self::bytes('connection')),
            7,
            $endpoint,
            InstallationBinding::pbsLegacyNode('pbs-a', $endpoint),
            new PbsInstallationSnapshot(
                new PbsVersion(3, 4, 4, '3.4.4', '1', 'repo'),
                'pbs-a',
                new PbsNodeStatus('pbs-a', 1, 100, 20, 100, 20, 80),
                null,
                PbsDatastoreScanScope::installationWide(),
                $configuration,
                $configuration,
                [],
                [],
                [],
            ),
        );
    }

    private function pveRead(): ConnectionInstallationRead
    {
        return new ConnectionInstallationRead(
            new ConnectionId(self::bytes('connection')),
            7,
            new EndpointId(self::bytes('endpoint')),
            InstallationBinding::pveStandalone('pve-a'),
            new PveInstallationSnapshot(
                new PveVersion(9, 0, 0, '9.0', '9.0.0', 'repo'),
                new PvePermissionAssessment([]),
                new PveClusterTopology(PveClusterMode::Standalone, null, null, null, null, [new PveClusterNode('pve-a', true, 0, true)], []),
                new PveResourceInventory([new PveNodeResource('pve-a', 'online')], [], [], []),
            ),
        );
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId(self::bytes('worker')),
            new CollectorCycleToken(self::bytes('cycle')),
            1,
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
        );
    }

    private static function id(string $seed): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes($seed));
    }

    private static function bytes(string $seed): string
    {
        return substr(hash('sha256', $seed, true), 0, 16);
    }
}

final class RecordingContentReader implements SelectedEndpointPbsContentReader
{
    public int $reads = 0;
    public ?EndpointId $endpointId = null;
    public int $revision = 0;
    /** @var list<PbsDatastoreId> */ public array $datastores = [];

    public function __construct(private readonly PbsContentSnapshot $snapshot, private readonly ?\Throwable $failure = null) {}

    public function read(ConnectionId $connectionId, EndpointId $endpointId, int $expectedRevision, array $datastores, ConnectionReadCheckpoint $checkpoint): PbsContentSnapshot
    {
        ++$this->reads;
        $this->endpointId = $endpointId;
        $this->revision = $expectedRevision;
        $this->datastores = $datastores;
        if (null !== $this->failure) { throw $this->failure; }
        return $this->snapshot;
    }
}

final class RecordingContentStore implements PbsContentStore
{
    /** @var list<PbsContentRunStart> */ public array $begun = [];
    /** @var list<PbsContentCommit> */ public array $applied = [];
    /** @var list<PbsContentRunFailure> */ public array $failed = [];

    public function __construct(
        private readonly ?string $failAt,
        private readonly ?\Throwable $criticalFailure,
        private readonly bool $failTerminalization,
    ) {}

    public function begin(CollectorLease $lease, PbsContentRunStart $start): void
    {
        $this->begun[] = $start;
        if ('begin' === $this->failAt) { throw $this->criticalFailure ?? new \RuntimeException('begin'); }
    }

    public function fail(CollectorLease $lease, PbsContentRunFailure $failure): void
    {
        if ($this->failTerminalization) { throw new \RuntimeException('fail'); }
        $this->failed[] = $failure;
    }

    public function apply(CollectorLease $lease, PbsContentCommit $commit): PbsContentApplyResult
    {
        $this->applied[] = $commit;
        if ('apply' === $this->failAt) { throw $this->criticalFailure ?? new \RuntimeException('apply'); }
        return new PbsContentApplyResult(PbsContentRunStatus::Succeeded, 1, 0, 0);
    }
}

final class SequentialContentIds implements InventoryIdentifierGenerator
{
    private int $next = 0;
    public function generate(): InventoryIdentifier
    {
        return new InventoryIdentifier(str_pad(pack('N', ++$this->next), 16, "\0", STR_PAD_LEFT));
    }
}

final class ContentFixedClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-12T00:00:00Z'); }
}

final class RecordingContentCheckpoint implements ConnectionReadCheckpoint
{
    public int $calls = 0;
    public function checkpoint(): void { ++$this->calls; }
}
