<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorShutdownRequested;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Domain\Shared\Clock;

final readonly class RunSelectedEndpointPbsContent implements SelectedEndpointPbsContent
{
    public function __construct(
        private SelectedEndpointPbsContentReader $reader,
        private PbsContentStore $store,
        private InventoryIdentifierGenerator $identifierGenerator,
        private Clock $clock,
    ) {}

    public function execute(
        CollectorLease $lease,
        InventoryIdentifier $parentRunId,
        ConnectionInstallationRead $read,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsContentRunStatus {
        if (!$read->snapshot instanceof PbsInstallationSnapshot) {
            throw new \InvalidArgumentException('A PBS content run requires a PBS installation snapshot.');
        }
        $checkpoint->checkpoint();
        $runId = $this->identifierGenerator->generate();
        $connectionId = new InventoryIdentifier($read->connectionId->bytes);
        $start = new PbsContentRunStart(
            $runId, $parentRunId, $connectionId, $read->endpointId,
            $read->binding, $read->expectedRevision, $this->clock->now(),
        );
        try {
            $this->store->begin($lease, $start);
            $checkpoint->checkpoint();
            $datastores = array_map(
                static fn (\App\Application\Proxmox\Pbs\PbsDatastoreDefinition $definition): \App\Application\Proxmox\Pbs\PbsDatastoreId => $definition->id,
                $read->snapshot->datastores,
            );
            $snapshot = $this->reader->read(
                $read->connectionId,
                $read->endpointId,
                $read->expectedRevision,
                $datastores,
                $checkpoint,
            );
            $checkpoint->checkpoint();
            $commit = new PbsContentCommit(
                $runId, $parentRunId, $connectionId, $read->endpointId,
                $read->binding, $read->expectedRevision, $snapshot, $this->clock->now(),
            );
            return $this->store->apply($lease, $commit)->status;
        } catch (CollectorLeaseOwnershipLost|CollectorShutdownRequested $critical) {
            $this->safeFail($lease, $runId, $connectionId, $critical instanceof CollectorShutdownRequested
                ? 'collector_shutdown_requested' : 'collector_lease_lost');
            throw $critical;
        } catch (\Throwable) {
            $this->safeFail($lease, $runId, $connectionId, 'pbs_content_failed');
            return PbsContentRunStatus::Failed;
        }
    }

    private function safeFail(
        CollectorLease $lease,
        InventoryIdentifier $runId,
        InventoryIdentifier $connectionId,
        string $errorCode,
    ): void {
        try {
            $this->store->fail($lease, new PbsContentRunFailure(
                $runId, $connectionId, $errorCode, $this->clock->now(),
            ));
        } catch (\Throwable) {
            // The store may already have terminalized the child before surfacing its failure.
        }
    }
}
