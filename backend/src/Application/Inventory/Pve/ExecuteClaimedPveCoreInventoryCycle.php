<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorCycleCoordinator;
use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\RunCollectorCycle;
use App\Application\Collector\StopRequested;
use App\Application\Inventory\Connection\ClaimedCycleCheckpoint;
use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadFailure;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\Connection\ConnectionScanCatalog;
use App\Application\Inventory\Connection\ConnectionScanTarget;
use App\Application\Inventory\Connection\InstallationBindingCatalog;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\Connection\ReadConnectionWithFailover;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Domain\Shared\Clock;

final readonly class ExecuteClaimedPveCoreInventoryCycle implements RunCollectorCycle
{
    public function __construct(
        private CollectorCycleCoordinator $cycleCoordinator,
        private ConnectionScanCatalog $scanCatalog,
        private InstallationBindingCatalog $bindingCatalog,
        private ReadConnectionWithFailover $connectionReader,
        private PveCoreInventoryMapper $mapper,
        private PveCoreInventoryStore $inventoryStore,
        private InventoryIdentifierGenerator $identifierGenerator,
        private Clock $clock,
        private StopRequested $stopRequested,
    ) {
    }

    public function execute(CollectorActiveCycle $cycle): ClaimedPveCoreInventoryCycleResult
    {
        $checkpoint = new ClaimedCycleCheckpoint($cycle, $this->cycleCoordinator, $this->stopRequested);
        $checkpoint->checkpoint();
        $targets = $this->scanCatalog->enabledTargets();
        $checkpoint->checkpoint();

        /** @var array{'succeeded': int, 'partial': int, 'failed': int} $pve */
        $pve = ['succeeded' => 0, 'partial' => 0, 'failed' => 0];
        $pbsDeferred = 0;

        foreach ($targets as $target) {
            $checkpoint->checkpoint();
            if (ProxmoxProduct::Pbs === $target->product) {
                ++$pbsDeferred;
                $checkpoint->checkpoint();
                continue;
            }

            $outcome = $this->executePveTarget($checkpoint, $target);
            ++$pve[$outcome];
        }

        $status = match (true) {
            $pve['failed'] > 0 && 0 === $pve['succeeded'] + $pve['partial'] => CollectorCycleStatus::Failed,
            $pve['partial'] > 0 || $pve['failed'] > 0 || $pbsDeferred > 0 => CollectorCycleStatus::Partial,
            default => CollectorCycleStatus::Succeeded,
        };
        $checkpoint->checkpoint();
        return new ClaimedPveCoreInventoryCycleResult(
            $status,
            $pve['succeeded'],
            $pve['partial'],
            $pve['failed'],
            $pbsDeferred,
        );
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
            $commit = $this->mapper->map($runId, $read, $this->clock->now());
            $checkpoint->checkpoint();
            $result = $this->inventoryStore->apply($checkpoint->lease(), $commit);
            $checkpoint->checkpoint();

            return $result->status;
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
