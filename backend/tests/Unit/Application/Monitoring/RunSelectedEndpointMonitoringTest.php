<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Monitoring;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorShutdownRequested;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Monitoring\MapSelectedEndpointMonitoring;
use App\Application\Monitoring\MonitoringApplyResult;
use App\Application\Monitoring\MonitoringCommit;
use App\Application\Monitoring\MonitoringConflict;
use App\Application\Monitoring\MonitoringCursorCatalog;
use App\Application\Monitoring\MonitoringCursorKind;
use App\Application\Monitoring\MonitoringRunFailure;
use App\Application\Monitoring\MonitoringRunKind;
use App\Application\Monitoring\MonitoringRunStart;
use App\Application\Monitoring\MonitoringRunStatus;
use App\Application\Monitoring\MonitoringRunStore;
use App\Application\Monitoring\MonitoringWindowPlanner;
use App\Application\Monitoring\PbsExternalMonitoringSnapshot;
use App\Application\Monitoring\RunSelectedEndpointMonitoring;
use App\Application\Monitoring\SelectedEndpointMonitoringReader;
use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreCapacity;
use App\Application\Proxmox\Pbs\PbsDatastoreConfigurationSnapshot;
use App\Application\Proxmox\Pbs\PbsDatastoreDefinition;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Application\Proxmox\Pve\PveBackupInventorySnapshot;
use App\Application\Proxmox\Pve\PveBackupJobCapabilities;
use App\Application\Proxmox\Pve\PveBackupJobInventory;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use App\Application\Proxmox\Pve\PveNodeResource;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageInventorySnapshot;
use App\Application\Proxmox\Pve\PveTaskArchiveWindow;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveTaskStreamScanResult;
use App\Application\Proxmox\Pve\PveTaskStreamScanStatus;
use App\Application\Proxmox\Pve\PveVersion;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunSelectedEndpointMonitoringTest extends TestCase
{
    public function testReadsSelectedEndpointExactlyOnceAndPersistsBothChildren(): void
    {
        [$runner, $reader, $store, $checkpoint] = $this->runner();

        $result = $runner->execute($this->lease(), self::id('parent'), $this->read(), $checkpoint);

        self::assertTrue($result->isComplete());
        self::assertSame(1, $reader->pveReads);
        self::assertCount(2, $store->begun);
        self::assertSame(
            [MonitoringRunKind::ExternalJobs, MonitoringRunKind::ObservedTasks],
            array_column($store->applied, 'kind'),
        );
        self::assertSame([], $store->failed);
    }

    public function testOneChildApplyFailureIsTerminalizedWithoutSuppressingTheOtherChild(): void
    {
        [$runner, $reader, $store, $checkpoint] = $this->runner(MonitoringRunKind::ExternalJobs);

        $result = $runner->execute($this->lease(), self::id('parent'), $this->read(), $checkpoint);

        self::assertSame(MonitoringRunStatus::Failed, $result->jobs);
        self::assertSame(MonitoringRunStatus::Succeeded, $result->tasks);
        self::assertSame(1, $reader->pveReads);
        self::assertSame([MonitoringRunKind::ExternalJobs], $store->failed);
        self::assertSame([MonitoringRunKind::ObservedTasks], array_column($store->applied, 'kind'));
    }

    public function testPbsUsesOneCombinedReadAndPersistsBothFailedChildDiagnostics(): void
    {
        [$runner, $reader, $store, $checkpoint] = $this->runner(pbs: true);

        $result = $runner->execute($this->lease(), self::id('parent'), $this->pbsRead(), $checkpoint);

        self::assertSame(MonitoringRunStatus::Failed, $result->jobs);
        self::assertSame(MonitoringRunStatus::Failed, $result->tasks);
        self::assertSame(1, $reader->pbsReads);
        self::assertSame(
            [MonitoringRunKind::ExternalJobs, MonitoringRunKind::ObservedTasks],
            $store->failed,
        );
    }

    public function testConstructorRejectsEachInvalidProductWindow(): void
    {
        foreach ([[0, 1], [86_401, 1], [1, 0], [1, 86_401]] as [$pve, $pbs]) {
            try {
                $this->runner(pveMaximumWindow: $pve, pbsMaximumWindow: $pbs);
                self::fail('An invalid monitoring window was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testBeginReadAndPersistCriticalFailuresRemainFailClosed(): void
    {
        [$genericBegin, , $genericBeginStore, $checkpoint] = $this->runner(
            beginFailure: new \RuntimeException('begin failed'),
            beginFailureAt: 2,
        );
        $result = $genericBegin->execute($this->lease(), self::id('parent'), $this->read(), $checkpoint);
        self::assertSame(MonitoringRunStatus::Failed, $result->jobs);
        self::assertSame([MonitoringRunKind::ExternalJobs], $genericBeginStore->failed);

        [$lostBegin, , , $checkpoint] = $this->runner(
            beginFailure: new \App\Application\Collector\CollectorLeaseOwnershipLost('lost'),
            beginFailureAt: 1,
        );
        try {
            $lostBegin->execute($this->lease(), self::id('parent'), $this->read(), $checkpoint);
            self::fail('Begin lease loss was swallowed.');
        } catch (\App\Application\Collector\CollectorLeaseOwnershipLost) {
            self::addToAssertionCount(1);
        }

        [$readFailure, , $readFailureStore, $checkpoint] = $this->runner(
            readerFailure: new \RuntimeException('read failed'),
        );
        $result = $readFailure->execute($this->lease(), self::id('parent'), $this->read(), $checkpoint);
        self::assertSame(MonitoringRunStatus::Failed, $result->tasks);
        self::assertCount(2, $readFailureStore->failed);

        [$lostRead, , , $checkpoint] = $this->runner(
            readerFailure: new \App\Application\Collector\CollectorLeaseOwnershipLost('lost'),
        );
        $this->expectException(\App\Application\Collector\CollectorLeaseOwnershipLost::class);
        $lostRead->execute($this->lease(), self::id('parent'), $this->read(), $checkpoint);
    }

    public function testWrongProductSnapshotsAndEmptyTopologyFollowSafeReadPaths(): void
    {
        [$pveMismatch, , $pveStore, $checkpoint] = $this->runner();
        $pveRead = $this->read();
        $mismatchedPve = new ConnectionInstallationRead(
            $pveRead->connectionId,
            $pveRead->expectedRevision,
            $pveRead->endpointId,
            $pveRead->binding,
            $this->pbsRead()->snapshot,
        );
        self::assertSame(
            MonitoringRunStatus::Failed,
            $pveMismatch->execute($this->lease(), self::id('parent'), $mismatchedPve, $checkpoint)->jobs,
        );
        self::assertCount(2, $pveStore->failed);

        [$pbsMismatch, , $pbsStore, $checkpoint] = $this->runner();
        $pbsRead = $this->pbsRead();
        $mismatchedPbs = new ConnectionInstallationRead(
            $pbsRead->connectionId,
            $pbsRead->expectedRevision,
            $pbsRead->endpointId,
            $pbsRead->binding,
            $this->read()->snapshot,
        );
        self::assertSame(
            MonitoringRunStatus::Failed,
            $pbsMismatch->execute($this->lease(), self::id('parent'), $mismatchedPbs, $checkpoint)->tasks,
        );
        self::assertCount(2, $pbsStore->failed);

        [$emptyTopology, $reader, , $checkpoint] = $this->runner();
        $emptyTopology->execute($this->lease(), self::id('parent'), $this->read(emptyTopology: true), $checkpoint);
        self::assertSame(1, $reader->pveReads);
    }

    public function testPersistCriticalFailureIsRethrownAndFailureTerminalizationIsBestEffort(): void
    {
        [$critical, , , $checkpoint] = $this->runner(
            applyFailure: new \App\Application\Collector\CollectorLeaseOwnershipLost('lost'),
        );
        try {
            $critical->execute($this->lease(), self::id('parent'), $this->read(), $checkpoint);
            self::fail('Apply lease loss was swallowed.');
        } catch (\App\Application\Collector\CollectorLeaseOwnershipLost) {
            self::addToAssertionCount(1);
        }

        [$bestEffort, , , $checkpoint] = $this->runner(
            readerFailure: new \RuntimeException('read failed'),
            failFailure: new \RuntimeException('terminalization failed'),
        );
        self::assertSame(
            MonitoringRunStatus::Failed,
            $bestEffort->execute($this->lease(), self::id('parent'), $this->read(), $checkpoint)->jobs,
        );
    }

    /** @return iterable<string, array{int, bool, list<MonitoringRunKind>}> */
    public static function shutdownProvider(): iterable
    {
        yield 'after first begin' => [2, false, [MonitoringRunKind::ExternalJobs]];
        yield 'inside exact read' => [4, true, [
            MonitoringRunKind::ExternalJobs,
            MonitoringRunKind::ObservedTasks,
        ]];
        yield 'after jobs persisted' => [5, false, [MonitoringRunKind::ObservedTasks]];
    }

    /** @param list<MonitoringRunKind> $expectedFailures */
    #[DataProvider('shutdownProvider')]
    public function testGracefulShutdownTerminalizesOnlyStillRunningChildren(
        int $throwAt,
        bool $checkpointInsideRead,
        array $expectedFailures,
    ): void {
        [$runner, , $store, $checkpoint] = $this->runner(
            checkpointInsideRead: $checkpointInsideRead,
            shutdownAt: $throwAt,
        );

        try {
            $runner->execute($this->lease(), self::id('parent'), $this->read(), $checkpoint);
            self::fail('The shutdown request was swallowed.');
        } catch (CollectorShutdownRequested) {
            self::assertSame($expectedFailures, $store->failed);
        }
    }

    /** @return array{RunSelectedEndpointMonitoring, RecordingMonitoringReader, RecordingMonitoringStore, ShutdownCheckpoint} */
    private function runner(
        ?MonitoringRunKind $failApply = null,
        bool $checkpointInsideRead = false,
        ?int $shutdownAt = null,
        bool $pbs = false,
        ?\Throwable $readerFailure = null,
        ?\Throwable $beginFailure = null,
        ?int $beginFailureAt = null,
        ?\Throwable $applyFailure = null,
        ?\Throwable $failFailure = null,
        int $pveMaximumWindow = 86_400,
        int $pbsMaximumWindow = 86_400,
    ): array {
        $reader = new RecordingMonitoringReader(
            $this->monitoringSnapshot(),
            $checkpointInsideRead,
            $pbs ? new PbsExternalMonitoringSnapshot(null, null, null, null, null, [
                'acl' => 'permission_denied',
                'prune' => 'permission_denied',
                'sync' => 'permission_denied',
                'verify' => 'permission_denied',
                'tasks' => 'permission_denied',
            ]) : null,
            $readerFailure,
        );
        $store = new RecordingMonitoringStore(
            $failApply,
            $beginFailure,
            $beginFailureAt,
            $applyFailure,
            $failFailure,
        );
        $checkpoint = new ShutdownCheckpoint($shutdownAt, $this->lease());
        return [
            new RunSelectedEndpointMonitoring(
                $reader,
                new MonitoringWindowPlanner(new EmptyMonitoringCursors()),
                new MapSelectedEndpointMonitoring(),
                $store,
                new SequentialMonitoringIds(),
                new MonitoringFixedClock(),
                $pveMaximumWindow,
                $pbsMaximumWindow,
            ),
            $reader,
            $store,
            $checkpoint,
        ];
    }

    private function pbsRead(): ConnectionInstallationRead
    {
        $id = new PbsDatastoreId('store_a');
        $configuration = new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), [$id]);
        return new ConnectionInstallationRead(
            new ConnectionId(self::bytes('connection')),
            1,
            new EndpointId(self::bytes('endpoint')),
            InstallationBinding::pbsLegacyNode('pbs-a', new EndpointId(self::bytes('endpoint'))),
            new PbsInstallationSnapshot(
                new PbsVersion(3, 4, 4, '3.4.4', '1', 'repo'),
                'pbs-a',
                new PbsNodeStatus('pbs-a', 1, 100, 20, 100, 20, 80),
                null,
                PbsDatastoreScanScope::installationWide(),
                $configuration,
                $configuration,
                [new PbsDatastoreDefinition($id, PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null)],
                [new PbsDatastoreCapacity($id, PbsDatastoreBackendType::Filesystem, 100, 20, 80)],
                [],
            ),
        );
    }

    private function read(bool $emptyTopology = false): ConnectionInstallationRead
    {
        $core = new PveInstallationSnapshot(
            new PveVersion(9, 0, 0, '9.0', '9.0.0', 'repo'),
            new PvePermissionAssessment([]),
            new PveClusterTopology(
                PveClusterMode::Standalone,
                null,
                null,
                null,
                null,
                $emptyTopology ? [] : [new PveClusterNode('pve-a', true, 0, true)],
                [],
            ),
            new PveResourceInventory(
                $emptyTopology ? [] : [new PveNodeResource('pve-a', 'online')],
                [],
                [],
                [],
            ),
        );
        return new ConnectionInstallationRead(
            new ConnectionId(self::bytes('connection')),
            1,
            new EndpointId(self::bytes('endpoint')),
            InstallationBinding::pveStandalone('pve-a'),
            new PveInventorySnapshot(
                $core,
                new PveStorageInventorySnapshot(null, null, [], []),
                $emptyTopology ? [] : ['pve-a'],
            ),
        );
    }

    private function monitoringSnapshot(): PveBackupInventorySnapshot
    {
        $window = new PveTaskArchiveWindow(1_752_184_800, 1_752_271_200);
        return new PveBackupInventorySnapshot(
            new PveBackupJobInventory(PveBackupJobCapabilities::forMajor(9), [], []),
            [],
            [],
            $window,
            [
                new PveTaskStreamScanResult('pve-a', PveTaskSource::Active, PveTaskStreamScanStatus::Complete, 1, 0),
                new PveTaskStreamScanResult('pve-a', PveTaskSource::Archive, PveTaskStreamScanStatus::Complete, 1, 0),
            ],
            2,
            0,
        );
    }

    private function lease(): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId(self::bytes('worker')),
            new CollectorCycleToken(self::bytes('cycle')),
            7,
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

final class RecordingMonitoringReader implements SelectedEndpointMonitoringReader
{
    public int $pveReads = 0;
    public int $pbsReads = 0;

    public function __construct(
        private readonly PveBackupInventorySnapshot $snapshot,
        private readonly bool $checkpointInsideRead,
        private readonly ?PbsExternalMonitoringSnapshot $pbsSnapshot,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function readPve(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        array $topologyNodes,
        PveTaskArchiveWindow $window,
        ConnectionReadCheckpoint $checkpoint,
    ): PveBackupInventorySnapshot {
        ++$this->pveReads;
        if (null !== $this->failure) {
            throw $this->failure;
        }
        if ($this->checkpointInsideRead) {
            $checkpoint->checkpoint();
        }
        return $this->snapshot;
    }

    public function readPbs(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        string $node,
        PbsTaskWindow $window,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsExternalMonitoringSnapshot {
        ++$this->pbsReads;
        if (null !== $this->failure) {
            throw $this->failure;
        }
        if (null === $this->pbsSnapshot) {
            throw new \LogicException('No PBS monitoring snapshot was configured.');
        }
        return $this->pbsSnapshot;
    }
}

final class RecordingMonitoringStore implements MonitoringRunStore
{
    /** @var list<MonitoringRunStart> */ public array $begun = [];
    /** @var list<MonitoringCommit> */ public array $applied = [];
    /** @var list<MonitoringRunKind> */ public array $failed = [];
    /** @var array<string, MonitoringRunKind> */ private array $kindByRun = [];

    private int $beginCalls = 0;

    public function __construct(
        private readonly ?MonitoringRunKind $failApply,
        private readonly ?\Throwable $beginFailure = null,
        private readonly ?int $beginFailureAt = null,
        private readonly ?\Throwable $applyFailure = null,
        private readonly ?\Throwable $failFailure = null,
    ) {
    }

    public function begin(CollectorLease $lease, MonitoringRunStart $start): void
    {
        ++$this->beginCalls;
        if (null !== $this->beginFailure && $this->beginCalls === $this->beginFailureAt) {
            throw $this->beginFailure;
        }
        $this->begun[] = $start;
        $this->kindByRun[bin2hex($start->runId->binary())] = $start->kind;
    }

    public function fail(CollectorLease $lease, MonitoringRunFailure $failure): void
    {
        if (null !== $this->failFailure) {
            throw $this->failFailure;
        }
        $this->failed[] = $this->kindByRun[bin2hex($failure->runId->binary())];
    }

    public function apply(CollectorLease $lease, MonitoringCommit $commit): MonitoringApplyResult
    {
        if (null !== $this->applyFailure) {
            throw $this->applyFailure;
        }
        if ($this->failApply === $commit->kind) {
            throw new MonitoringConflict('queued failure');
        }
        $this->applied[] = $commit;
        return new MonitoringApplyResult($commit->status(), 0, 0, 0);
    }

    public function finishFailedCommit(CollectorLease $lease, MonitoringCommit $commit, string $errorCode): void
    {
        $this->failed[] = $commit->kind;
    }
}

final class EmptyMonitoringCursors implements MonitoringCursorCatalog
{
    public function oldestCompletedUntil(
        ConnectionId $connectionId,
        MonitoringCursorKind $kind,
        array $scopeKeys,
    ): ?DateTimeImmutable {
        return null;
    }
}

final class SequentialMonitoringIds implements InventoryIdentifierGenerator
{
    private int $next = 0;
    public function generate(): InventoryIdentifier
    {
        return new InventoryIdentifier(substr(hash('sha256', 'monitoring-id-'.++$this->next, true), 0, 16));
    }
}

final class MonitoringFixedClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-07-12T00:00:00Z');
    }
}

final class ShutdownCheckpoint implements ConnectionReadCheckpoint
{
    private int $calls = 0;

    public function __construct(
        private readonly ?int $throwAt,
        private readonly CollectorLease $lease,
    ) {
    }

    public function checkpoint(): void
    {
        ++$this->calls;
        if ($this->calls === $this->throwAt) {
            throw new CollectorShutdownRequested(new CollectorActiveCycle(
                $this->lease,
                new DateTimeImmutable('2025-07-12T00:00:00Z'),
                0,
            ));
        }
    }
}
