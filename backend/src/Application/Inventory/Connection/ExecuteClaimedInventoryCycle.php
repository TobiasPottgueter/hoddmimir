<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorCycleCoordinator;
use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\RunCollectorCycle;
use App\Application\Collector\StopRequested;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Inventory\Capability\BuildVerifiedCapabilityProfile;
use App\Application\Inventory\Capability\CapabilitySnapshotConflict;
use App\Application\Inventory\Capability\CapabilitySnapshotObservation;
use App\Application\Inventory\Capability\CapabilitySnapshotStore;
use App\Application\Inventory\Pbs\PbsInventoryConflict;
use App\Application\Inventory\Pbs\PbsInventoryMapper;
use App\Application\Inventory\Pbs\PbsInventoryMappingFailure;
use App\Application\Inventory\Pbs\PbsInventoryStore;
use App\Application\Inventory\Pbs\PersistPbsEndpointReadAttempts;
use App\Application\Inventory\PbsContent\PbsContentRunStatus;
use App\Application\Inventory\PbsContent\SelectedEndpointPbsContent;
use App\Application\Inventory\Pve\PersistPveEndpointReadAttempts;
use App\Application\Inventory\Pve\PveCoreInventoryConflict;
use App\Application\Inventory\Pve\PveCoreInventoryConflictCode;
use App\Application\Inventory\Pve\PveCoreInventoryMappingFailure;
use App\Application\Inventory\Pve\PveCoreInventoryStore;
use App\Application\Inventory\Pve\PveInventoryMapper;
use App\Application\Inventory\Pve\PveSyncRunFailure;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Monitoring\SelectedEndpointMonitoring;
use App\Domain\Shared\Clock;

final readonly class ExecuteClaimedInventoryCycle implements RunCollectorCycle
{
    public function __construct(
        private CollectorCycleCoordinator $cycleCoordinator,
        private ConnectionScanCatalog $scanCatalog,
        private InstallationBindingCatalog $bindingCatalog,
        private ReadConnectionWithFailover $connectionReader,
        private PveInventoryMapper $mapper,
        private PveCoreInventoryStore $inventoryStore,
        private PbsInventoryMapper $pbsMapper,
        private PbsInventoryStore $pbsInventoryStore,
        private InventoryIdentifierGenerator $identifierGenerator,
        private BuildVerifiedCapabilityProfile $capabilityProfileBuilder,
        private CapabilitySnapshotStore $capabilitySnapshotStore,
        private SelectedEndpointPbsContent $pbsContent,
        private SelectedEndpointMonitoring $monitoring,
        private Clock $clock,
        private StopRequested $stopRequested,
    ) {
    }

    public function execute(CollectorActiveCycle $cycle): ClaimedInventoryCycleResult
    {
        $checkpoint = new ClaimedCycleCheckpoint($cycle, $this->cycleCoordinator, $this->stopRequested);
        $checkpoint->checkpoint();
        $targets = $this->scanCatalog->enabledTargets();
        $checkpoint->checkpoint();

        /** @var array{'succeeded': int, 'partial': int, 'failed': int} $pve */
        $pve = ['succeeded' => 0, 'partial' => 0, 'failed' => 0];
        /** @var array{'succeeded': int, 'partial': int, 'failed': int} $pbs */
        $pbs = ['succeeded' => 0, 'partial' => 0, 'failed' => 0];

        foreach ($targets as $target) {
            $checkpoint->checkpoint();
            if (ProxmoxProduct::Pbs === $target->product) {
                $outcome = $this->executePbsTarget($checkpoint, $target);
                ++$pbs[$outcome];
                continue;
            }

            $outcome = $this->executePveTarget($checkpoint, $target);
            ++$pve[$outcome];
        }

        $status = match (true) {
            $pve['failed'] + $pbs['failed'] > 0
                && 0 === $pve['succeeded'] + $pve['partial'] + $pbs['succeeded'] + $pbs['partial'] => CollectorCycleStatus::Failed,
            $pve['partial'] + $pbs['partial'] > 0 || $pve['failed'] + $pbs['failed'] > 0 => CollectorCycleStatus::Partial,
            default => CollectorCycleStatus::Succeeded,
        };
        $checkpoint->checkpoint();
        return new ClaimedInventoryCycleResult(
            $status,
            $pve['succeeded'],
            $pve['partial'],
            $pve['failed'],
            $pbs['succeeded'],
            $pbs['partial'],
            $pbs['failed'],
        );
    }

    /** @return 'succeeded'|'partial'|'failed' */
    private function executePbsTarget(ClaimedCycleCheckpoint $checkpoint, ConnectionScanTarget $target): string
    {
        $checkpoint->checkpoint();
        $binding = $this->bindingCatalog->bindingFor($target->connectionId);
        $checkpoint->checkpoint();
        $runId = $this->identifierGenerator->generate();
        $connectionId = $this->inventoryId($target->connectionId);
        $checkpoint->checkpoint();
        try {
            $this->pbsInventoryStore->beginRun($checkpoint->lease(), new PveSyncRunStart(
                $runId,
                $connectionId,
                $target->expectedRevision,
                $this->clock->now(),
            ));
        } catch (PbsInventoryConflict $conflict) {
            if (!$conflict->connectionChanged) {
                throw $conflict;
            }
            $checkpoint->checkpoint();
            return 'failed';
        }
        $checkpoint->checkpoint();

        try {
            $read = $this->connectionReader->read(
                $target,
                $binding,
                $checkpoint,
                new PersistPbsEndpointReadAttempts(
                    $checkpoint,
                    $this->pbsInventoryStore,
                    $this->identifierGenerator,
                    $this->clock,
                    $runId,
                    $connectionId,
                ),
            );
            $this->persistCapabilities($checkpoint, $runId, $connectionId, $target, $read);
            $commit = $this->pbsMapper->map($runId, $read, $this->clock->now());
            $checkpoint->checkpoint();
            $result = $this->pbsInventoryStore->apply($checkpoint->lease(), $commit);
            $checkpoint->checkpoint();
            if ($result->diagnosticOnly || 'failed' === $result->status) {
                return $result->status;
            }
            $content = $this->pbsContent->execute($checkpoint->lease(), $runId, $read, $checkpoint);
            $monitoring = $this->monitoring->execute($checkpoint->lease(), $runId, $read, $checkpoint);
            return 'succeeded' === $result->status
                && PbsContentRunStatus::Succeeded === $content
                && $monitoring->isComplete()
                ? 'succeeded' : 'partial';
        } catch (ConnectionReadFailure $failure) {
            $this->finishFailedPbsRun($checkpoint, $runId, $connectionId, $target, $failure->failureCode);
            return 'failed';
        } catch (PbsInventoryMappingFailure) {
            $this->finishFailedPbsRun(
                $checkpoint,
                $runId,
                $connectionId,
                $target,
                ConnectionReadFailureCode::SnapshotInvalid,
            );
            return 'failed';
        } catch (CapabilitySnapshotConflict $conflict) {
            $this->finishFailedPbsRun(
                $checkpoint,
                $runId,
                $connectionId,
                $target,
                $conflict->connectionChanged
                    ? ConnectionReadFailureCode::ConnectionChanged
                    : ConnectionReadFailureCode::SnapshotInvalid,
            );
            return 'failed';
        } catch (PbsInventoryConflict $conflict) {
            if (!$conflict->connectionChanged) {
                throw $conflict;
            }
            $this->finishFailedPbsRun(
                $checkpoint,
                $runId,
                $connectionId,
                $target,
                ConnectionReadFailureCode::ConnectionChanged,
            );
            return 'failed';
        }
    }

    private function finishFailedPbsRun(
        ClaimedCycleCheckpoint $checkpoint,
        InventoryIdentifier $runId,
        InventoryIdentifier $connectionId,
        ConnectionScanTarget $target,
        ConnectionReadFailureCode $failureCode,
    ): void {
        $checkpoint->checkpoint();
        $this->pbsInventoryStore->finishWithoutSnapshot($checkpoint->lease(), new PveSyncRunFailure(
            $runId,
            $connectionId,
            $target->expectedRevision,
            $failureCode,
            $this->clock->now(),
        ));
        $checkpoint->checkpoint();
    }

    /** @return 'succeeded'|'partial'|'failed' */
    private function executePveTarget(
        ClaimedCycleCheckpoint $checkpoint,
        ConnectionScanTarget $target,
    ): string {
        $checkpoint->checkpoint();
        $binding = $this->bindingCatalog->bindingFor($target->connectionId);
        $checkpoint->checkpoint();

        $runId = $this->identifierGenerator->generate();
        $connectionId = $this->inventoryId($target->connectionId);
        $checkpoint->checkpoint();
        try {
            $this->inventoryStore->beginRun($checkpoint->lease(), new PveSyncRunStart(
                $runId,
                $connectionId,
                $target->expectedRevision,
                $this->clock->now(),
            ));
        } catch (PveCoreInventoryConflict $conflict) {
            if (PveCoreInventoryConflictCode::ConnectionChanged !== $conflict->failureCode) {
                throw $conflict;
            }
            $checkpoint->checkpoint();

            return 'failed';
        }
        $checkpoint->checkpoint();

        try {
            $read = $this->connectionReader->read(
                $target,
                $binding,
                $checkpoint,
                new PersistPveEndpointReadAttempts(
                    $checkpoint,
                    $this->inventoryStore,
                    $this->identifierGenerator,
                    $this->clock,
                    $runId,
                    $connectionId,
                ),
            );
            $this->persistCapabilities($checkpoint, $runId, $connectionId, $target, $read);
            $commit = $this->mapper->map($runId, $read, $this->clock->now());
            $checkpoint->checkpoint();
            $result = $this->inventoryStore->apply($checkpoint->lease(), $commit);
            $checkpoint->checkpoint();
            if ($result->diagnosticOnly || 'failed' === $result->status) {
                return $result->status;
            }
            $monitoring = $this->monitoring->execute($checkpoint->lease(), $runId, $read, $checkpoint);
            return 'succeeded' === $result->status && $monitoring->isComplete()
                ? 'succeeded' : 'partial';
        } catch (ConnectionReadFailure $failure) {
            $this->finishFailedRun($checkpoint, $runId, $connectionId, $target, $failure->failureCode);

            return 'failed';
        } catch (PveCoreInventoryMappingFailure) {
            $this->finishFailedRun(
                $checkpoint,
                $runId,
                $connectionId,
                $target,
                ConnectionReadFailureCode::SnapshotInvalid,
            );

            return 'failed';
        } catch (CapabilitySnapshotConflict $conflict) {
            $this->finishFailedRun(
                $checkpoint,
                $runId,
                $connectionId,
                $target,
                $conflict->connectionChanged
                    ? ConnectionReadFailureCode::ConnectionChanged
                    : ConnectionReadFailureCode::SnapshotInvalid,
            );
            return 'failed';
        } catch (PveCoreInventoryConflict $conflict) {
            if (PveCoreInventoryConflictCode::ConnectionChanged !== $conflict->failureCode) {
                throw $conflict;
            }
            $this->finishFailedRun(
                $checkpoint,
                $runId,
                $connectionId,
                $target,
                ConnectionReadFailureCode::ConnectionChanged,
            );

            return 'failed';
        }
    }

    private function persistCapabilities(
        ClaimedCycleCheckpoint $checkpoint,
        InventoryIdentifier $runId,
        InventoryIdentifier $connectionId,
        ConnectionScanTarget $target,
        ConnectionInstallationRead $read,
    ): void {
        $checkpoint->checkpoint();
        try {
            $profile = $this->capabilityProfileBuilder->build($read);
            $this->capabilitySnapshotStore->persist(
                $checkpoint->lease(),
                new CapabilitySnapshotObservation(
                    $runId,
                    $connectionId,
                    $target->expectedRevision,
                    $read->endpointId,
                    $profile,
                    $this->clock->now(),
                ),
            );
        } catch (\InvalidArgumentException $exception) {
            throw CapabilitySnapshotConflict::invariant(
                'The verified capability snapshot is invalid.',
                $exception,
            );
        }
        $checkpoint->checkpoint();
    }

    private function finishFailedRun(
        ClaimedCycleCheckpoint $checkpoint,
        InventoryIdentifier $runId,
        InventoryIdentifier $connectionId,
        ConnectionScanTarget $target,
        ConnectionReadFailureCode $failureCode,
    ): void {
        $checkpoint->checkpoint();
        $this->inventoryStore->finishWithoutSnapshot($checkpoint->lease(), new PveSyncRunFailure(
            $runId,
            $connectionId,
            $target->expectedRevision,
            $failureCode,
            $this->clock->now(),
        ));
        $checkpoint->checkpoint();
    }

    private function inventoryId(ConnectionId $connectionId): InventoryIdentifier
    {
        return new InventoryIdentifier($connectionId->bytes);
    }
}
