<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use App\Application\Proxmox\Pve\PveStorageConfiguration;
use App\Application\Proxmox\Pve\PveStorageIssue;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use DateTimeImmutable;

final readonly class MapPveInventorySnapshot implements PveInventoryMapper
{
    public function __construct(private PveCoreInventoryMapper $coreMapper)
    {
    }

    public function map(
        InventoryIdentifier $runId,
        ConnectionInstallationRead $read,
        DateTimeImmutable $observedAt,
    ): PveInventoryCommit {
        if (!$read->snapshot instanceof PveInventorySnapshot) {
            throw PveCoreInventoryMappingFailure::nonPveRead();
        }

        $coreRead = new ConnectionInstallationRead(
            $read->connectionId,
            $read->expectedRevision,
            $read->endpointId,
            $read->binding,
            $read->snapshot->core,
        );
        $core = $this->coreMapper->map($runId, $coreRead, $observedAt);
        $storage = $read->snapshot->storage;
        $storageStatus = $this->storageStatus($storage->issues, $storage->isAuthoritative());
        $nodeScopes = $this->nodeScopes($core, $storage->issues, $storageStatus);
        [$storages, $states] = $this->safePositiveObservations($read->snapshot, $observedAt);

        return new PveInventoryCommit(
            $core,
            new PveCoreScopeResult(PveCoreScope::Storages, $storageStatus),
            $nodeScopes,
            $storages,
            $states,
        );
    }

    /** @param list<PveStorageIssue> $issues */
    private function storageStatus(array $issues, bool $authoritative): InventoryScopeStatus
    {
        if ($authoritative) {
            return InventoryScopeStatus::Complete;
        }
        foreach ($issues as $issue) {
            if (PveStorageIssueCode::ConfigurationReadFailed === $issue->code
                || PveStorageIssueCode::NodeFanoutExceeded === $issue->code) {
                return InventoryScopeStatus::Failed;
            }
        }

        return InventoryScopeStatus::Partial;
    }

    /**
     * @param list<PveStorageIssue> $issues
     *
     * @return list<PveNodeStorageScopeResult>
     */
    private function nodeScopes(
        PveCoreInventoryCommit $core,
        array $issues,
        InventoryScopeStatus $storageStatus,
    ): array {
        $scopes = [];
        foreach ($core->nodes as $node) {
            $status = InventoryScopeStatus::Complete;
            if (InventoryScopeStatus::Failed === $storageStatus
                && $this->hasIssue($issues, PveStorageIssueCode::NodeFanoutExceeded)) {
                $status = InventoryScopeStatus::Failed;
            }
            foreach ($issues as $issue) {
                if ($issue->node !== $node->name) {
                    continue;
                }
                $status = PveStorageIssueCode::NodeReadFailed === $issue->code
                    ? InventoryScopeStatus::Failed
                    : InventoryScopeStatus::Partial;
                if (InventoryScopeStatus::Failed === $status) {
                    break;
                }
            }
            $scopes[] = new PveNodeStorageScopeResult($node->name, $status);
        }

        return $scopes;
    }

    /**
     * @return array{list<PveStorageObservation>, list<PveNodeStorageStateObservation>}
     */
    private function safePositiveObservations(
        PveInventorySnapshot $snapshot,
        DateTimeImmutable $observedAt,
    ): array {
        $start = $snapshot->storage->startConfiguration;
        $end = $snapshot->storage->endConfiguration;
        if (null === $start || null === $end || !$start->hasSameVisibleDefinitions($end)) {
            return [[], []];
        }

        $endById = [];
        foreach ($end->definitions as $definition) {
            $endById[$definition->storageId] = $definition;
        }
        $observationsByKey = [];
        foreach ($snapshot->storage->observations as $observation) {
            $observationsByKey[$observation->node."\0".$observation->storageId] = $observation;
        }

        $storages = [];
        $states = [];
        foreach ($start->definitions as $definition) {
            if (!$definition->supportsBackup()
                || !isset($endById[$definition->storageId])
                || $definition->signature() !== $endById[$definition->storageId]->signature()
                || ('pbs' === $definition->storageType && null === $definition->pbsMapping)
                || !$this->hasCompleteExpectedNodeSet(
                    $definition,
                    $snapshot->topologyNodeNames,
                    $snapshot->storage->issues,
                )
                || $this->hasBlockingStorageIssue($snapshot->storage->issues, $definition->storageId)) {
                continue;
            }

            $expectedNodes = $this->expectedNodes($definition, $snapshot->topologyNodeNames);
            $acceptedStates = [];
            $fullyEvidenced = true;
            foreach ($expectedNodes as $node) {
                $key = $node."\0".$definition->storageId;
                $observation = $observationsByKey[$key] ?? null;
                if (null === $observation
                    || $this->hasBlockingNodeStorageIssue($snapshot->storage->issues, $definition->storageId, $node)) {
                    $fullyEvidenced = false;
                    break;
                }
                $acceptedStates[] = PveNodeStorageStateObservation::fromReadObservation($observation);
            }
            if (!$fullyEvidenced) {
                continue;
            }

            $storages[] = PveStorageObservation::fromConfiguration($definition, $observedAt);
            array_push($states, ...$acceptedStates);
        }

        return [$storages, $states];
    }

    /**
     * @param list<string> $nodes
     *
     * @return list<string>
     */
    private function expectedNodes(PveStorageConfiguration $definition, array $nodes): array
    {
        $expected = [];
        foreach ($nodes as $node) {
            if ($definition->isExpectedOn($node)) {
                $expected[] = $node;
            }
        }

        return $expected;
    }

    /**
     * @param list<string>          $topologyNodes
     * @param list<PveStorageIssue> $issues
     */
    private function hasCompleteExpectedNodeSet(
        PveStorageConfiguration $definition,
        array $topologyNodes,
        array $issues,
    ): bool {
        if ($definition->disabled) {
            return true;
        }

        if (null === $definition->nodeAllowlist) {
            return !$this->hasIssue($issues, PveStorageIssueCode::IncompleteTopology);
        }

        $visibleNodes = array_fill_keys($topologyNodes, true);
        foreach ($definition->nodeAllowlist as $node) {
            if (!isset($visibleNodes[$node])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<PveStorageIssue> $issues */
    private function hasBlockingStorageIssue(array $issues, string $storageId): bool
    {
        foreach ($issues as $issue) {
            if ($issue->storageId === $storageId
                && PveStorageIssueCode::InvalidCapacity !== $issue->code) {
                return true;
            }
        }

        return false;
    }

    /** @param list<PveStorageIssue> $issues */
    private function hasBlockingNodeStorageIssue(array $issues, string $storageId, string $node): bool
    {
        foreach ($issues as $issue) {
            if ($issue->node === $node
                && (null === $issue->storageId || $issue->storageId === $storageId)
                && PveStorageIssueCode::InvalidCapacity !== $issue->code) {
                return true;
            }
        }

        return false;
    }

    /** @param list<PveStorageIssue> $issues */
    private function hasIssue(array $issues, PveStorageIssueCode $code): bool
    {
        foreach ($issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }
}
