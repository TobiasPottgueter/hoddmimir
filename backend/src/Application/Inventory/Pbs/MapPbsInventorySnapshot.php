<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsInventoryIssueCode;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MapPbsInventorySnapshot implements PbsInventoryMapper
{
    public function map(
        InventoryIdentifier $runId,
        ConnectionInstallationRead $read,
        DateTimeImmutable $observedAt,
    ): PbsInventoryCommit {
        if (!$read->snapshot instanceof PbsInstallationSnapshot || !$read->snapshot->scope->installationWide) {
            throw PbsInventoryMappingFailure::invalidSnapshot();
        }
        try {
            $snapshot = $read->snapshot;
            [$datastores, $capacities, $statusScopes] = $this->safeDatastoreEvidence($snapshot);

            return new PbsInventoryCommit(
                $runId,
                new InventoryIdentifier($read->connectionId->bytes),
                new InventoryIdentifier($read->endpointId->bytes),
                $read->expectedRevision,
                $read->binding,
                new PbsInventoryScopeResult(
                    PbsInventoryScope::System,
                    '@installation',
                    $this->systemStatus($snapshot),
                ),
                new PbsInventoryScopeResult(
                    PbsInventoryScope::Datastores,
                    '@installation',
                    $this->datastoreStatus($snapshot),
                ),
                $statusScopes,
                new PbsServerObservation($snapshot->node, $snapshot->version, $snapshot->nodeStatus),
                $datastores,
                $capacities,
                $observedAt,
            );
        } catch (InvalidArgumentException) {
            throw PbsInventoryMappingFailure::invalidSnapshot();
        }
    }

    private function systemStatus(PbsInstallationSnapshot $snapshot): InventoryScopeStatus
    {
        foreach ($snapshot->issues as $issue) {
            if (PbsInventoryIssueCode::NodeStatusReadFailed === $issue->code
                || PbsInventoryIssueCode::IdentityReadFailed === $issue->code) {
                return InventoryScopeStatus::Failed;
            }
            if (PbsInventoryIssueCode::MissingSystemStatusPermission === $issue->code) {
                return InventoryScopeStatus::Partial;
            }
        }

        return null !== $snapshot->nodeStatus ? InventoryScopeStatus::Complete : InventoryScopeStatus::Partial;
    }

    private function datastoreStatus(PbsInstallationSnapshot $snapshot): InventoryScopeStatus
    {
        $status = InventoryScopeStatus::Complete;
        foreach ($snapshot->issues as $issue) {
            if (!$this->isDatastoreIssue($issue->code)) {
                continue;
            }
            if (PbsInventoryIssueCode::ConfigurationReadFailed === $issue->code
                || PbsInventoryIssueCode::DatastoreListReadFailed === $issue->code
                || PbsInventoryIssueCode::DatastoreFanoutExceeded === $issue->code) {
                return InventoryScopeStatus::Failed;
            }
            $status = InventoryScopeStatus::Partial;
        }

        return $status;
    }

    /**
     * @return array{list<PbsDatastoreObservation>, list<PbsCapacityObservation>, list<PbsInventoryScopeResult>}
     */
    private function safeDatastoreEvidence(PbsInstallationSnapshot $snapshot): array
    {
        $start = $snapshot->startConfiguration;
        $end = $snapshot->endConfiguration;
        if (null === $start || null === $end || !$start->sameConfiguration($end)) {
            return [[], [], []];
        }

        $expected = [];
        foreach ($start->datastores as $id) {
            $expected[$id->value] = true;
        }
        $capacities = [];
        foreach ($snapshot->capacities as $capacity) {
            $capacities[$capacity->id->value] = $capacity;
        }

        $stores = [];
        $observedCapacities = [];
        $scopes = [];
        foreach ($snapshot->datastores as $definition) {
            $id = $definition->id->value;
            if (!isset($expected[$id])) {
                continue;
            }
            $stores[] = PbsDatastoreObservation::fromDefinition($definition);
            $capacity = $capacities[$id] ?? null;
            if (null !== $capacity && $capacity->backendType === $definition->backendType) {
                $observedCapacities[] = PbsCapacityObservation::fromCapacity($capacity);
                $scopeStatus = InventoryScopeStatus::Complete;
            } else {
                $scopeStatus = $this->statusFailureFor($snapshot, $id);
            }
            $scopes[] = new PbsInventoryScopeResult(PbsInventoryScope::DatastoreStatus, $id, $scopeStatus);
        }

        return [$stores, $observedCapacities, $scopes];
    }

    private function statusFailureFor(PbsInstallationSnapshot $snapshot, string $datastoreId): InventoryScopeStatus
    {
        foreach ($snapshot->issues as $issue) {
            if (PbsInventoryIssueCode::DatastoreFanoutExceeded === $issue->code
                || (PbsInventoryIssueCode::DatastoreStatusReadFailed === $issue->code
                    && $issue->datastoreId === $datastoreId)) {
                return InventoryScopeStatus::Failed;
            }
        }

        return InventoryScopeStatus::Partial;
    }

    private function isDatastoreIssue(PbsInventoryIssueCode $code): bool
    {
        return PbsInventoryIssueCode::MissingSystemStatusPermission !== $code
            && PbsInventoryIssueCode::NodeStatusReadFailed !== $code
            && PbsInventoryIssueCode::IdentityReadFailed !== $code;
    }
}
