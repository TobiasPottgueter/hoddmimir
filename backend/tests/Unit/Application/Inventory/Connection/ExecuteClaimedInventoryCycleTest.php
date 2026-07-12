<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Connection;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorClaimDecision;
use App\Application\Collector\CollectorCycleCoordinator;
use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorShutdownRequested;
use App\Application\Collector\CollectorHeartbeatStore;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorScheduleSnapshot;
use App\Application\Collector\CollectorScheduleStore;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorWorkerStatus;
use App\Application\Collector\StopRequested;
use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\ConnectionScanCatalog;
use App\Application\Inventory\Connection\ConnectionScanTarget;
use App\Application\Inventory\Connection\ClaimedCycleCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointInstallationReader;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\EndpointReadAttemptOutcome;
use App\Application\Inventory\Connection\EndpointScanReference;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\InstallationBindingCatalog;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\Connection\ReadConnectionWithFailover;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\Connection\ClaimedInventoryCycleResult;
use App\Application\Inventory\Connection\ExecuteClaimedInventoryCycle;
use App\Application\Inventory\Pve\MapPveCoreInventorySnapshot;
use App\Application\Inventory\Pve\PersistPveEndpointReadAttempts;
use App\Application\Inventory\Pve\PveCoreApplyResult;
use App\Application\Inventory\Pve\PveCoreApplyStatus;
use App\Application\Inventory\Pve\PveCoreInventoryCommit;
use App\Application\Inventory\Pve\PveCoreInventoryConflict;
use App\Application\Inventory\Pve\PveCoreInventoryMapper;
use App\Application\Inventory\Pve\PveCoreInventoryMappingFailure;
use App\Application\Inventory\Pve\PveCoreInventoryStore;
use App\Application\Inventory\Pve\PveCoreScope;
use App\Application\Inventory\Pve\PveCoreScopeResult;
use App\Application\Inventory\Pve\PveEndpointAttempt;
use App\Application\Inventory\Pve\PveInventoryCommit;
use App\Application\Inventory\Pve\PveInventoryMapper;
use App\Application\Inventory\Pve\PveNodeStorageScopeResult;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Inventory\Pve\PveSyncRunFailure;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Inventory\Pbs\MapPbsInventorySnapshot;
use App\Application\Inventory\Pbs\PbsInventoryApplyResult;
use App\Application\Inventory\Pbs\PbsInventoryApplyStatus;
use App\Application\Inventory\Pbs\PbsInventoryCommit;
use App\Application\Inventory\Pbs\PbsInventoryConflict;
use App\Application\Inventory\Pbs\PbsInventoryMapper;
use App\Application\Inventory\Pbs\PbsInventoryMappingFailure;
use App\Application\Inventory\Pbs\PbsInventoryStore;
use App\Application\Monitoring\ConnectionMonitoringResult;
use App\Application\Monitoring\MonitoringRunStatus;
use App\Application\Monitoring\SelectedEndpointMonitoring;
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
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveGuestResource;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveNodeResource;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Worker\MonotonicClock;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ExecuteClaimedInventoryCycleTest extends TestCase
{
    public function testSuccessfulCoreIsDegradedToPartialWhenMonitoringIsIncomplete(): void
    {
        $monitoring = new RecordingSelectedEndpointMonitoring([
            new ConnectionMonitoringResult(MonitoringRunStatus::Succeeded, MonitoringRunStatus::Failed),
        ]);
        [$executor] = $this->executor(
            [$this->target('first', ProxmoxProduct::Pve, [1])],
            [$this->snapshot('first')],
            monitoring: $monitoring,
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Partial, $result->status);
        self::assertSame(1, $result->pvePartial);
        self::assertSame(1, $monitoring->calls);
    }

    public function testDiagnosticOnlyParentSkipsMonitoringPersistence(): void
    {
        $monitoring = new RecordingSelectedEndpointMonitoring([]);
        [$executor] = $this->executor(
            [$this->target('first', ProxmoxProduct::Pve, [1])],
            [$this->snapshot('first')],
            [new PveCoreApplyResult(PveCoreApplyStatus::Partial, 0, 0, 0, true)],
            monitoring: $monitoring,
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Partial, $result->status);
        self::assertSame(0, $monitoring->calls);
    }

    public function testEmptyCatalogFinishesSucceededWithoutOpeningARun(): void
    {
        [$executor, $schedule, $store] = $this->executor([]);

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Succeeded, $result->status);
        self::assertSame([], $schedule->finished);
        self::assertSame(3, $schedule->renewals);
        self::assertSame([], $store->events);
    }

    public function testPbsTargetIsReadPersistedAndCountedAsSucceeded(): void
    {
        [$executor, $schedule, $store, $reader, $pbsStore] = $this->executor(
            [$this->target('pbs', ProxmoxProduct::Pbs, [1])],
            [$this->pbsSnapshot()],
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Succeeded, $result->status);
        self::assertSame(1, $result->pbsSucceeded);
        self::assertSame(0, $result->pveSucceeded);
        self::assertSame([str_repeat(chr(1), 16)], $reader->endpointIds);
        self::assertSame([], $store->events);
        self::assertSame(['begin:pbs', 'attempt:pbs:1:selected', 'apply:pbs'], $pbsStore->events);
        self::assertSame([], $schedule->finished);
    }

    public function testUnexpectedInvalidArgumentExceptionFromPbsReadPropagatesUnchanged(): void
    {
        $sentinel = new \InvalidArgumentException('unexpected PBS invariant');
        [$executor, $schedule, $pveStore, , $pbsStore] = $this->executor(
            [$this->target('pbs', ProxmoxProduct::Pbs, [1])],
            [$sentinel],
        );

        try {
            $executor->execute($this->cycle());
            self::fail('An unexpected PBS InvalidArgumentException was classified as an operational failure.');
        } catch (\InvalidArgumentException $failure) {
            self::assertSame($sentinel, $failure);
        }

        self::assertSame([], $pveStore->events);
        self::assertSame(['begin:pbs'], $pbsStore->events);
        self::assertSame([], $schedule->finished);
    }

    public function testPbsReadAndMappingFailuresAreTerminalizedAndDoNotStopLaterTargets(): void
    {
        $mapper = new QueuedPbsInventoryMapper([
            PbsInventoryMappingFailure::invalidSnapshot(),
            null,
        ]);
        [$executor, , , , $store] = $this->executor(
            [
                $this->target('pbs-a', ProxmoxProduct::Pbs, [1]),
                $this->target('pbs-b', ProxmoxProduct::Pbs, [2]),
                $this->target('pbs-c', ProxmoxProduct::Pbs, [3]),
            ],
            [
                EndpointReadFailure::for(EndpointReadFailureCode::Authentication),
                $this->pbsSnapshot(),
                $this->pbsSnapshot(),
            ],
            pbsMapper: $mapper,
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Partial, $result->status);
        self::assertSame(1, $result->pbsSucceeded);
        self::assertSame(2, $result->pbsFailed);
        self::assertSame(
            [ConnectionReadFailureCode::TerminalEndpointFailure, ConnectionReadFailureCode::SnapshotInvalid],
            array_map(static fn (PveSyncRunFailure $failure): ConnectionReadFailureCode => $failure->failureCode, $store->failures),
        );
    }

    public function testPbsConnectionDriftBeforeRunOpenIsIsolatedWithoutFinishingANonexistentRun(): void
    {
        [$executor, , , , $store] = $this->executor(
            [
                $this->target('pbs-a', ProxmoxProduct::Pbs, [1]),
                $this->target('pbs-b', ProxmoxProduct::Pbs, [2]),
            ],
            [$this->pbsSnapshot()],
            pbsBeginResults: [PbsInventoryConflict::connectionChanged(), null],
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Partial, $result->status);
        self::assertSame(1, $result->pbsFailed);
        self::assertSame(1, $result->pbsSucceeded);
        self::assertSame([], $store->failures);
        self::assertSame(['begin:pbs-b', 'attempt:pbs-b:1:selected', 'apply:pbs-b'], $store->events);
    }

    public function testNonOperationalPbsBeginConflictPropagates(): void
    {
        [$executor, , , , $store] = $this->executor(
            [$this->target('pbs', ProxmoxProduct::Pbs, [1])],
            pbsBeginResults: [new PbsInventoryConflict('begin invariant')],
        );

        $this->expectExceptionObject(new PbsInventoryConflict('begin invariant'));
        try {
            $executor->execute($this->cycle());
        } finally {
            self::assertSame([], $store->events);
            self::assertSame([], $store->failures);
        }
    }

    public function testPbsApplyConnectionDriftIsTerminalizedAndNonOperationalConflictPropagates(): void
    {
        [$driftExecutor, , , , $driftStore] = $this->executor(
            [$this->target('pbs', ProxmoxProduct::Pbs, [1])],
            [$this->pbsSnapshot()],
            pbsApplyResults: [PbsInventoryConflict::connectionChanged()],
        );

        $drift = $driftExecutor->execute($this->cycle());
        self::assertSame(CollectorCycleStatus::Failed, $drift->status);
        self::assertSame(ConnectionReadFailureCode::ConnectionChanged, $driftStore->failures[0]->failureCode);

        [$invariantExecutor, , , , $invariantStore] = $this->executor(
            [$this->target('pbs', ProxmoxProduct::Pbs, [1])],
            [$this->pbsSnapshot()],
            pbsApplyResults: [new PbsInventoryConflict('apply invariant')],
        );
        try {
            $invariantExecutor->execute($this->cycle());
            self::fail('A non-operational PBS apply invariant was swallowed.');
        } catch (PbsInventoryConflict $conflict) {
            self::assertSame('apply invariant', $conflict->getMessage());
        }
        self::assertSame([], $invariantStore->failures);
    }

    public function testPbsApplyPartialAndFailedStatusesAreAggregated(): void
    {
        [$executor] = $this->executor(
            [
                $this->target('pbs-a', ProxmoxProduct::Pbs, [1]),
                $this->target('pbs-b', ProxmoxProduct::Pbs, [2]),
            ],
            [$this->pbsSnapshot(), $this->pbsSnapshot()],
            pbsApplyResults: [
                new PbsInventoryApplyResult(PbsInventoryApplyStatus::Partial, 0, 1, 0, false),
                new PbsInventoryApplyResult(PbsInventoryApplyStatus::Failed, 0, 0, 0, true),
            ],
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Partial, $result->status);
        self::assertSame(1, $result->pbsPartial);
        self::assertSame(1, $result->pbsFailed);
    }

    public function testEveryPbsTargetRunsIndependently(): void
    {
        [$executor, , $store, $reader, $pbsStore] = $this->executor(
            [
                $this->target('pbs-a', ProxmoxProduct::Pbs, [1]),
                $this->target('pbs-b', ProxmoxProduct::Pbs, [2]),
                $this->target('pbs-c', ProxmoxProduct::Pbs, [3]),
            ],
            [$this->pbsSnapshot(), $this->pbsSnapshot(), $this->pbsSnapshot()],
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Succeeded, $result->status());
        self::assertSame(3, $result->pbsSucceeded);
        self::assertCount(3, $reader->endpointIds);
        self::assertSame([], $store->events);
        self::assertCount(9, $pbsStore->events);
    }

    public function testShutdownDuringDeferredPbsCheckpointPropagatesTheLatestClaim(): void
    {
        [$executor, $schedule] = $this->executor(
            [
                $this->target('pbs-a', ProxmoxProduct::Pbs, [1]),
                $this->target('pbs-b', ProxmoxProduct::Pbs, [2]),
            ],
            stopRequested: new StopAfterReadChecks(8),
        );

        try {
            $executor->execute($this->cycle());
            self::fail('The PBS checkpoint ignored the shutdown flag.');
        } catch (CollectorShutdownRequested $shutdown) {
            self::assertSame(7, $shutdown->cycle->lease->fencingToken);
        }

        self::assertSame(4, $schedule->renewals);
    }

    public function testItSerializesPveTargetsAndAggregatesSucceededAndPartialApplyResults(): void
    {
        $targets = [
            $this->target('first', ProxmoxProduct::Pve, [1]),
            $this->target('second', ProxmoxProduct::Pve, [2]),
        ];
        [$executor, $schedule, $store, $reader] = $this->executor(
            $targets,
            [$this->snapshot('node-a'), $this->snapshot('node-b')],
            [
                new PveCoreApplyResult(PveCoreApplyStatus::Succeeded, 3, 0, 0, false),
                new PveCoreApplyResult(PveCoreApplyStatus::Partial, 0, 3, 0, false),
            ],
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(1, $result->pveSucceeded);
        self::assertSame(1, $result->pvePartial);
        self::assertSame(CollectorCycleStatus::Partial, $result->status);
        self::assertSame(
            ['begin:first', 'attempt:first:1:selected', 'apply:first', 'begin:second', 'attempt:second:1:selected', 'apply:second'],
            $store->events,
        );
        self::assertSame([str_repeat(chr(1), 16), str_repeat(chr(2), 16)], $reader->endpointIds);
        self::assertSame(23, $schedule->renewals);
    }

    public function testReadFailuresAreIsolatedAndEveryOpenedRunEndsExactlyOnce(): void
    {
        $targets = [
            $this->target('failover-success', ProxmoxProduct::Pve, [1, 2]),
            $this->target('terminal', ProxmoxProduct::Pve, [3]),
            $this->target('empty', ProxmoxProduct::Pve, []),
        ];
        [$executor, , $store] = $this->executor($targets, [
            EndpointReadFailure::for(EndpointReadFailureCode::Transport),
            $this->snapshot('node-a'),
            EndpointReadFailure::for(EndpointReadFailureCode::Authentication),
        ]);

        $result = $executor->execute($this->cycle());

        self::assertSame(1, $result->pveSucceeded);
        self::assertSame(2, $result->pveFailed);
        self::assertSame(CollectorCycleStatus::Partial, $result->status);
        self::assertSame(
            [
                'begin:failover-success',
                'attempt:failover-success:1:failover',
                'attempt:failover-success:2:selected',
                'apply:failover-success',
                'begin:terminal',
                'attempt:terminal:1:terminal',
                'finish:terminal:terminal_endpoint_failure',
                'begin:empty',
                'finish:empty:no_endpoints',
            ],
            $store->events,
        );
        self::assertCount(3, $store->starts);
        self::assertCount(2, $store->failures);
    }

    public function testPveTargetsWithoutAnyUsableSnapshotFinishTheCycleFailed(): void
    {
        [$executor, $schedule, $store] = $this->executor(
            [
                $this->target('terminal', ProxmoxProduct::Pve, [1]),
                $this->target('empty', ProxmoxProduct::Pve, []),
            ],
            [EndpointReadFailure::for(EndpointReadFailureCode::Authentication)],
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Failed, $result->status);
        self::assertSame(2, $result->pveFailed);
        self::assertSame([], $schedule->finished);
        self::assertCount(2, $store->failures);
    }

    public function testMappingAndApplyConflictsUseStableTypedTerminalCodesAndDoNotStopLaterTargets(): void
    {
        $targets = [
            $this->target('mapping', ProxmoxProduct::Pve, [1]),
            $this->target('conflict', ProxmoxProduct::Pve, [2]),
            $this->target('after', ProxmoxProduct::Pve, [3]),
        ];
        $mapper = new QueuedPveCoreMapper([
            PveCoreInventoryMappingFailure::bindingMismatch(),
            null,
            null,
        ]);
        [$executor, , $store] = $this->executor(
            $targets,
            [$this->snapshot('node-a'), $this->snapshot('node-b'), $this->snapshot('node-c')],
            [
                PveCoreInventoryConflict::connectionChanged(),
                new PveCoreApplyResult(PveCoreApplyStatus::Succeeded, 3, 0, 0, false),
            ],
            $mapper,
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(1, $result->pveSucceeded);
        self::assertSame(2, $result->pveFailed);
        self::assertSame(
            [ConnectionReadFailureCode::SnapshotInvalid, ConnectionReadFailureCode::ConnectionChanged],
            array_map(static fn (PveSyncRunFailure $failure): ConnectionReadFailureCode => $failure->failureCode, $store->failures),
        );
        self::assertNotEmpty($store->events);
        self::assertSame('apply:after', $store->events[count($store->events) - 1]);
    }

    public function testResultRejectsNegativeOrInconsistentCounters(): void
    {
        try {
        new ClaimedInventoryCycleResult(CollectorCycleStatus::Succeeded, -1, 0, 0, 0, 0, 0);
            self::fail('A negative cycle counter was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        new ClaimedInventoryCycleResult(CollectorCycleStatus::Succeeded, 0, 1, 0, 0, 0, 0);
    }

    public function testResultRejectsNonTerminalCycleStatusesAndCoversFailedResultContract(): void
    {
        $failed = new ClaimedInventoryCycleResult(CollectorCycleStatus::Failed, 0, 0, 2, 0, 0, 1);
        self::assertSame(CollectorCycleStatus::Failed, $failed->status);

        $this->expectException(\InvalidArgumentException::class);
        new ClaimedInventoryCycleResult(CollectorCycleStatus::Cancelled, 0, 0, 0, 0, 0, 0);
    }

    public function testApplyResultUsesTypedStatusAndRejectsNegativeCounters(): void
    {
        $result = new PveCoreApplyResult(PveCoreApplyStatus::Failed, 0, 0, 0, true);
        self::assertSame('failed', $result->status);

        $this->expectException(\InvalidArgumentException::class);
        new PveCoreApplyResult(PveCoreApplyStatus::Failed, -1, 0, 0, true);
    }

    public function testValidFailedApplyResultTerminatesThroughApplyAndFailsTheCycle(): void
    {
        [$executor, $schedule, $store] = $this->executor(
            [$this->target('first', ProxmoxProduct::Pve, [1])],
            [$this->snapshot('node-a')],
            [new PveCoreApplyResult(PveCoreApplyStatus::Failed, 0, 0, 0, true)],
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(CollectorCycleStatus::Failed, $result->status);
        self::assertSame(1, $result->pveFailed);
        self::assertSame([], $store->failures);
        self::assertSame(['begin:first', 'attempt:first:1:selected', 'apply:first'], $store->events);
        self::assertSame([], $schedule->finished);
    }

    public function testAttemptPersistenceMapsEveryTypedOutcome(): void
    {
        $schedule = new CycleScheduleStore();
        $checkpoint = new ClaimedCycleCheckpoint(
            $this->cycle(),
            new CollectorCycleCoordinator($schedule, new NullHeartbeatStore(), new FixedMonotonicClock()),
            new NeverStopRequested(),
        );
        $store = new RecordingPveCoreInventoryStore([]);
        $run = new InventoryIdentifier(self::namedBytes('attempt-run'));
        $connection = new InventoryIdentifier(self::namedBytes('first'));
        $store->beginRun($checkpoint->lease(), new PveSyncRunStart(
            $run,
            $connection,
            1,
            new DateTimeImmutable('2026-07-11T00:00:00Z'),
        ));
        $sink = new PersistPveEndpointReadAttempts(
            $checkpoint,
            $store,
            new NamedInventoryIdentifierGenerator(),
            new FixedClock(),
            $run,
            $connection,
        );
        $cases = [
            [EndpointReadAttemptOutcome::Selected, null],
            [EndpointReadAttemptOutcome::Failover, EndpointReadFailureCode::Transport],
            [EndpointReadAttemptOutcome::Terminal, EndpointReadFailureCode::Authentication],
        ];
        foreach ($cases as $offset => [$outcome, $failure]) {
            $sink->finish(
                $sink->start(new EndpointId(str_repeat(chr($offset + 1), 16)), $offset + 1),
                $outcome,
                $failure,
            );
        }

        self::assertSame(
            [
                'begin:first',
                'attempt:first:1:selected',
                'attempt:first:2:failover',
                'attempt:first:3:terminal',
            ],
            $store->events,
        );
    }

    public function testNonOperationalInventoryConflictPropagatesAndIsNotMisclassifiedOrTerminalized(): void
    {
        [$executor, $schedule, $store] = $this->executor(
            [$this->target('conflict', ProxmoxProduct::Pve, [1])],
            [$this->snapshot('node-a')],
            [new PveCoreInventoryConflict('selected endpoint invariant')],
        );

        try {
            $executor->execute($this->cycle());
            self::fail('A non-operational inventory invariant was swallowed.');
        } catch (PveCoreInventoryConflict $conflict) {
            self::assertSame('selected endpoint invariant', $conflict->getMessage());
        }

        self::assertSame([], $store->failures);
        self::assertSame([], $schedule->finished);
    }

    public function testConnectionDriftBeforeRunOpenIsIsolatedWithoutTerminalizingANonexistentRun(): void
    {
        [$executor, $schedule, $store] = $this->executor(
            [
                $this->target('first', ProxmoxProduct::Pve, [1]),
                $this->target('second', ProxmoxProduct::Pve, [2]),
            ],
            [$this->snapshot('node-b')],
            [new PveCoreApplyResult(PveCoreApplyStatus::Succeeded, 3, 0, 0, false)],
            beginResults: [PveCoreInventoryConflict::connectionChanged(), null],
        );

        $result = $executor->execute($this->cycle());

        self::assertSame(1, $result->pveFailed);
        self::assertSame(1, $result->pveSucceeded);
        self::assertSame(CollectorCycleStatus::Partial, $result->status);
        self::assertCount(1, $store->starts);
        self::assertSame([], $store->failures);
        self::assertSame(['begin:second', 'attempt:second:1:selected', 'apply:second'], $store->events);
        self::assertSame([], $schedule->finished);
    }

    public function testNonOperationalBeginConflictPropagatesWithoutOpeningOrFinishingARun(): void
    {
        [$executor, $schedule, $store] = $this->executor(
            [$this->target('first', ProxmoxProduct::Pve, [1])],
            beginResults: [new PveCoreInventoryConflict('begin invariant')],
        );

        try {
            $executor->execute($this->cycle());
            self::fail('A non-operational begin conflict was swallowed.');
        } catch (PveCoreInventoryConflict $conflict) {
            self::assertSame('begin invariant', $conflict->getMessage());
        }

        self::assertSame([], $store->starts);
        self::assertSame([], $store->failures);
        self::assertSame([], $schedule->finished);
    }

    /**
     * @param list<ConnectionScanTarget> $targets
     * @param list<PveInstallationSnapshot|PbsInstallationSnapshot|\Throwable> $reads
     * @param list<PveCoreApplyResult|PveCoreInventoryConflict> $applyResults
     * @param list<PveCoreInventoryConflict|null> $beginResults
     * @param list<PbsInventoryApplyResult|PbsInventoryConflict> $pbsApplyResults
     * @param list<PbsInventoryConflict|null> $pbsBeginResults
     * @return array{ExecuteClaimedInventoryCycle, CycleScheduleStore, RecordingPveCoreInventoryStore, QueueEndpointReader, RecordingPbsInventoryStore}
     */
    private function executor(
        array $targets,
        array $reads = [],
        array $applyResults = [],
        ?PveInventoryMapper $mapper = null,
        array $beginResults = [],
        ?StopRequested $stopRequested = null,
        array $pbsApplyResults = [],
        array $pbsBeginResults = [],
        ?PbsInventoryMapper $pbsMapper = null,
        ?SelectedEndpointMonitoring $monitoring = null,
    ): array {
        $schedule = new CycleScheduleStore();
        $coordinator = new CollectorCycleCoordinator(
            $schedule,
            new NullHeartbeatStore(),
            new FixedMonotonicClock(),
        );
        $store = new RecordingPveCoreInventoryStore($applyResults, $beginResults);
        $pbsStore = new RecordingPbsInventoryStore($pbsApplyResults, $pbsBeginResults);
        $reader = new QueueEndpointReader($reads);
        $ids = new NamedInventoryIdentifierGenerator();

        return [
            new ExecuteClaimedInventoryCycle(
                $coordinator,
                new FixedScanCatalog($targets),
                new NodeInstallationBindingCatalog(),
                new ReadConnectionWithFailover($reader),
                $mapper ?? new CoreOnlyTestInventoryMapper(new MapPveCoreInventorySnapshot()),
                $store,
                $pbsMapper ?? new MapPbsInventorySnapshot(),
                $pbsStore,
                $ids,
                $monitoring ?? new RecordingSelectedEndpointMonitoring([]),
                new FixedClock(),
                $stopRequested ?? new NeverStopRequested(),
            ),
            $schedule,
            $store,
            $reader,
            $pbsStore,
        ];
    }

    /** @param list<int<0, 255>> $endpointBytes */
    private function target(string $name, ProxmoxProduct $product, array $endpointBytes): ConnectionScanTarget
    {
        $endpoints = [];
        foreach ($endpointBytes as $byte) {
            $endpoints[] = new EndpointScanReference(new EndpointId(str_repeat(chr($byte), 16)), $byte);
        }

        return new ConnectionScanTarget(new ConnectionId(self::namedBytes($name)), 1, $product, $endpoints);
    }

    private function cycle(): CollectorActiveCycle
    {
        return new CollectorActiveCycle(
            new CollectorLease(
                new CollectorWorkerId(str_repeat('w', 16)),
                new CollectorCycleToken(str_repeat('t', 16)),
                7,
                new DateTimeImmutable('2026-07-11T01:00:00Z'),
            ),
            new DateTimeImmutable('2026-07-11T00:00:00Z'),
            0,
        );
    }

    private function snapshot(string $node): PveInstallationSnapshot
    {
        return new PveInstallationSnapshot(
            new PveVersion(9, 0, 0, '9.0', '9.0.0', 'repo'),
            new PvePermissionAssessment([]),
            new PveClusterTopology(
                PveClusterMode::Standalone,
                null,
                null,
                null,
                null,
                [new PveClusterNode($node, true, 0, true)],
                [],
            ),
            new PveResourceInventory(
                [new PveNodeResource($node, 'online')],
                [new PveGuestResource(PveGuestType::Qemu, 100, $node, 'guest', false, 'running')],
                [],
                [],
            ),
        );
    }

    private function pbsSnapshot(): PbsInstallationSnapshot
    {
        $id = new PbsDatastoreId('store_a');
        $configuration = new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), [$id]);
        return new PbsInstallationSnapshot(
            new PbsVersion(3, 4, 4, '3.4.4', '1', 'repo'),
            'pbs',
            new PbsNodeStatus('pbs', 1, 100, 20, 100, 20, 80),
            null,
            PbsDatastoreScanScope::installationWide(),
            $configuration,
            $configuration,
            [new PbsDatastoreDefinition($id, PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null)],
            [new PbsDatastoreCapacity($id, PbsDatastoreBackendType::Filesystem, 100, 20, 80)],
            [],
        );
    }

    private static function namedBytes(string $name): string
    {
        return substr(hash('sha256', $name, true), 0, 16);
    }
}

final class RecordingSelectedEndpointMonitoring implements SelectedEndpointMonitoring
{
    /** @var list<ConnectionMonitoringResult> */
    private array $results;
    public int $calls = 0;

    /** @param list<ConnectionMonitoringResult> $results */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function execute(
        CollectorLease $lease,
        InventoryIdentifier $parentRunId,
        ConnectionInstallationRead $read,
        ConnectionReadCheckpoint $checkpoint,
    ): ConnectionMonitoringResult {
        ++$this->calls;
        return array_shift($this->results)
            ?? new ConnectionMonitoringResult(MonitoringRunStatus::Succeeded, MonitoringRunStatus::Succeeded);
    }
}

final class FixedScanCatalog implements ConnectionScanCatalog
{
    /** @param list<ConnectionScanTarget> $targets */
    public function __construct(private readonly array $targets) {}
    public function enabledTargets(): array { return $this->targets; }
}

final class NodeInstallationBindingCatalog implements InstallationBindingCatalog
{
    public function bindingFor(ConnectionId $connectionId): ?InstallationBinding
    {
        return null;
    }
}

final class QueueEndpointReader implements EndpointInstallationReader
{
    /** @var list<PveInstallationSnapshot|PbsInstallationSnapshot|\Throwable> */
    private array $reads;
    /** @var list<string> */
    public array $endpointIds = [];
    /** @param list<PveInstallationSnapshot|PbsInstallationSnapshot|\Throwable> $reads */
    public function __construct(array $reads) { $this->reads = $reads; }
    public function read(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        ProxmoxProduct $product,
        \App\Application\Inventory\Connection\ConnectionReadCheckpoint $checkpoint,
    ): PveInstallationSnapshot|PbsInstallationSnapshot {
        $this->endpointIds[] = $endpointId->bytes;
        $read = array_shift($this->reads);
        if ($read instanceof \Throwable) { throw $read; }
        if (!$read instanceof PveInstallationSnapshot && !$read instanceof PbsInstallationSnapshot) {
            throw new \RuntimeException('No fake endpoint read queued.');
        }
        return $read;
    }
}

final class RecordingPbsInventoryStore implements PbsInventoryStore
{
    /** @var list<string> */ public array $events = [];
    /** @var list<PveSyncRunFailure> */ public array $failures = [];
    /** @var array<string, string> */ private array $names = [];
    /** @var list<PbsInventoryApplyResult|PbsInventoryConflict> */ private array $applyResults;
    /** @var list<PbsInventoryConflict|null> */ private array $beginResults;

    /**
     * @param list<PbsInventoryApplyResult|PbsInventoryConflict> $applyResults
     * @param list<PbsInventoryConflict|null> $beginResults
     */
    public function __construct(array $applyResults = [], array $beginResults = [])
    {
        $this->applyResults = $applyResults;
        $this->beginResults = $beginResults;
    }

    public function beginRun(CollectorLease $lease, PveSyncRunStart $start): void
    {
        $result = array_shift($this->beginResults);
        if ($result instanceof PbsInventoryConflict) { throw $result; }
        $name = $this->connectionName($start->connectionId);
        $this->names[bin2hex($start->runId->binary())] = $name;
        $this->events[] = 'begin:'.$name;
    }

    public function recordEndpointAttempt(CollectorLease $lease, PveEndpointAttempt $attempt): void
    {
        $this->events[] = sprintf(
            'attempt:%s:%d:%s',
            $this->names[bin2hex($attempt->runId->binary())],
            $attempt->attemptNumber,
            $attempt->outcome->value,
        );
    }

    public function finishWithoutSnapshot(CollectorLease $lease, PveSyncRunFailure $failure): void
    {
        $this->failures[] = $failure;
        $this->events[] = 'finish:'.$this->names[bin2hex($failure->runId->binary())].':'.$failure->failureCode->value;
    }

    public function apply(CollectorLease $lease, PbsInventoryCommit $inventory): PbsInventoryApplyResult
    {
        $this->events[] = 'apply:'.$this->names[bin2hex($inventory->runId->binary())];
        $result = array_shift($this->applyResults)
            ?? new PbsInventoryApplyResult(PbsInventoryApplyStatus::Succeeded, 1, 0, 0, false);
        if ($result instanceof PbsInventoryConflict) { throw $result; }
        return $result;
    }

    private function connectionName(InventoryIdentifier $connectionId): string
    {
        foreach (['pbs', 'pbs-a', 'pbs-b', 'pbs-c'] as $name) {
            if (hash_equals(substr(hash('sha256', $name, true), 0, 16), $connectionId->binary())) {
                return $name;
            }
        }
        return 'unknown';
    }
}

final class QueuedPbsInventoryMapper implements PbsInventoryMapper
{
    /** @var list<PbsInventoryMappingFailure|null> */
    private array $outcomes;

    /** @param list<PbsInventoryMappingFailure|null> $outcomes */
    public function __construct(array $outcomes) { $this->outcomes = $outcomes; }

    public function map(
        InventoryIdentifier $runId,
        ConnectionInstallationRead $read,
        DateTimeImmutable $observedAt,
    ): PbsInventoryCommit {
        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof PbsInventoryMappingFailure) { throw $outcome; }
        return (new MapPbsInventorySnapshot())->map($runId, $read, $observedAt);
    }
}

final class RecordingPveCoreInventoryStore implements PveCoreInventoryStore
{
    /** @var list<PveCoreApplyResult|PveCoreInventoryConflict> */
    private array $applyResults;
    /** @var list<PveCoreInventoryConflict|null> */
    private array $beginResults;
    /** @var list<string> */
    public array $events = [];
    /** @var list<PveSyncRunStart> */
    public array $starts = [];
    /** @var list<PveSyncRunFailure> */
    public array $failures = [];
    /** @var array<string, string> */
    private array $names = [];
    /**
     * @param list<PveCoreApplyResult|PveCoreInventoryConflict> $applyResults
     * @param list<PveCoreInventoryConflict|null> $beginResults
     */
    public function __construct(array $applyResults, array $beginResults = [])
    {
        $this->applyResults = $applyResults;
        $this->beginResults = $beginResults;
    }
    public function beginRun(CollectorLease $lease, PveSyncRunStart $start): void
    {
        $result = array_shift($this->beginResults);
        if ($result instanceof PveCoreInventoryConflict) { throw $result; }
        $name = $this->connectionName($start->connectionId);
        $this->names[bin2hex($start->runId->binary())] = $name;
        $this->starts[] = $start;
        $this->events[] = 'begin:'.$name;
    }
    public function recordEndpointAttempt(CollectorLease $lease, PveEndpointAttempt $attempt): void
    {
        $this->events[] = sprintf(
            'attempt:%s:%d:%s',
            $this->names[bin2hex($attempt->runId->binary())],
            $attempt->attemptNumber,
            $attempt->outcome->value,
        );
    }
    public function finishWithoutSnapshot(CollectorLease $lease, PveSyncRunFailure $failure): void
    {
        $this->failures[] = $failure;
        $this->events[] = sprintf(
            'finish:%s:%s',
            $this->names[bin2hex($failure->runId->binary())],
            $failure->failureCode->value,
        );
    }
    public function apply(CollectorLease $lease, PveInventoryCommit $commit): PveCoreApplyResult
    {
        $this->events[] = 'apply:'.$this->names[bin2hex($commit->core->runId->binary())];
        $result = array_shift($this->applyResults)
            ?? new PveCoreApplyResult(PveCoreApplyStatus::Succeeded, 0, 0, 0, false);
        if ($result instanceof PveCoreInventoryConflict) { throw $result; }
        return $result;
    }
    private function connectionName(InventoryIdentifier $connectionId): string
    {
        foreach (['first', 'second', 'failover-success', 'terminal', 'empty', 'mapping', 'conflict', 'after'] as $name) {
            if (hash_equals(substr(hash('sha256', $name, true), 0, 16), $connectionId->binary())) { return $name; }
        }
        return 'unknown';
    }
}

final class QueuedPveCoreMapper implements PveInventoryMapper
{
    /** @var list<PveCoreInventoryMappingFailure|null> */
    private array $outcomes;
    /** @param list<PveCoreInventoryMappingFailure|null> $outcomes */
    public function __construct(array $outcomes) { $this->outcomes = $outcomes; }
    public function map(InventoryIdentifier $runId, ConnectionInstallationRead $read, DateTimeImmutable $observedAt): PveInventoryCommit
    {
        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof PveCoreInventoryMappingFailure) { throw $outcome; }
        return (new CoreOnlyTestInventoryMapper(new MapPveCoreInventorySnapshot()))->map($runId, $read, $observedAt);
    }
}

final readonly class CoreOnlyTestInventoryMapper implements PveInventoryMapper
{
    public function __construct(private PveCoreInventoryMapper $coreMapper) {}

    public function map(
        InventoryIdentifier $runId,
        ConnectionInstallationRead $read,
        DateTimeImmutable $observedAt,
    ): PveInventoryCommit {
        $core = $this->coreMapper->map($runId, $read, $observedAt);

        return new PveInventoryCommit(
            $core,
            new PveCoreScopeResult(PveCoreScope::Storages, InventoryScopeStatus::Complete),
            array_map(
                static fn ($node): PveNodeStorageScopeResult => new PveNodeStorageScopeResult(
                    $node->name,
                    InventoryScopeStatus::Complete,
                ),
                $core->nodes,
            ),
            [],
            [],
        );
    }
}

final class NamedInventoryIdentifierGenerator implements InventoryIdentifierGenerator
{
    private int $next = 0;
    public function generate(): InventoryIdentifier
    {
        return new InventoryIdentifier(substr(hash('sha256', 'generated-'.++$this->next, true), 0, 16));
    }
}

final class FixedClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-11T00:00:00Z'); }
}

final class FixedMonotonicClock implements MonotonicClock
{
    public function nowNanoseconds(): int { return 1_000_000; }
}

final class NeverStopRequested implements StopRequested
{
    private int $reads = 0;
    public function isStopRequested(): bool { ++$this->reads; return false; }
}

final class StopAfterReadChecks implements StopRequested
{
    private int $reads = 0;
    public function __construct(private readonly int $stopAt) {}
    public function isStopRequested(): bool { return ++$this->reads >= $this->stopAt; }
}

final class CycleScheduleStore implements CollectorScheduleStore
{
    public int $renewals = 0;
    /** @var list<CollectorCycleStatus> */
    public array $finished = [];
    public function bootstrap(int $gridWidthSeconds): CollectorScheduleSnapshot { throw new \LogicException('unused'); }
    public function claimDue(CollectorWorkerId $ownerId, CollectorCycleToken $cycleToken, int $leaseTtlSeconds): CollectorClaimDecision { throw new \LogicException('unused'); }
    public function renew(CollectorLease $lease, int $leaseTtlSeconds): CollectorLease
    {
        ++$this->renewals;
        return new CollectorLease($lease->ownerId, $lease->token, $lease->fencingToken, new DateTimeImmutable('2026-07-11T01:00:00Z'));
    }
    public function finalize(CollectorLease $lease, CollectorCycleStatus $status, int $durationMilliseconds): DateTimeImmutable
    {
        $this->finished[] = $status;
        return new DateTimeImmutable('2026-07-11T00:02:00Z');
    }
}

final class NullHeartbeatStore implements CollectorHeartbeatStore
{
    public function record(
        CollectorWorkerId $workerId,
        CollectorWorkerStatus $status,
        int $ttlSeconds,
        string $buildVersion,
        ?CollectorCycleToken $cycleToken = null,
        ?DateTimeImmutable $nextActionAt = null,
    ): void {}
    public function isFresh(CollectorWorkerId $workerId): bool { return true; }
}
