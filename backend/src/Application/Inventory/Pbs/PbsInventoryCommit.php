<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\InstallationBindingKind;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PbsInventoryCommit
{
    /** @var list<PbsInventoryScopeResult> */ public array $statusScopes;
    /** @var list<PbsDatastoreObservation> */ public array $datastores;
    /** @var list<PbsCapacityObservation> */ public array $capacities;
    public DateTimeImmutable $observedAt;

    /**
     * @param list<PbsInventoryScopeResult> $statusScopes
     * @param list<PbsDatastoreObservation> $datastores
     * @param list<PbsCapacityObservation>  $capacities
     */
    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $connectionId,
        public InventoryIdentifier $endpointId,
        public int $expectedConnectionRevision,
        public InstallationBinding $binding,
        public PbsInventoryScopeResult $systemScope,
        public PbsInventoryScopeResult $datastoreScope,
        array $statusScopes,
        public PbsServerObservation $server,
        array $datastores,
        array $capacities,
        DateTimeImmutable $observedAt,
    ) {
        if ($expectedConnectionRevision < 1
            || PbsInventoryScope::System !== $systemScope->scope
            || PbsInventoryScope::Datastores !== $datastoreScope->scope
            || '@installation' !== $systemScope->key
            || '@installation' !== $datastoreScope->key
            || ProxmoxProduct::Pbs !== $binding->product
            || (InstallationBindingKind::PbsInstance !== $binding->kind
                && InstallationBindingKind::PbsLegacyNode !== $binding->kind)) {
            throw new InvalidArgumentException('The PBS inventory commit header is invalid.');
        }
        $scopes = [];
        foreach ($statusScopes as $scope) {
            if (PbsInventoryScope::DatastoreStatus !== $scope->scope || isset($scopes[$scope->key])) {
                throw new InvalidArgumentException('The PBS datastore status scopes are invalid.');
            }
            $scopes[$scope->key] = $scope;
        }
        ksort($scopes, SORT_STRING);
        $this->statusScopes = array_values($scopes);

        $stores = [];
        foreach ($datastores as $datastore) {
            if (isset($stores[$datastore->id])) {
                throw new InvalidArgumentException('The PBS datastore observations contain duplicates.');
            }
            $stores[$datastore->id] = $datastore;
        }
        ksort($stores, SORT_STRING);
        $this->datastores = array_values($stores);

        $capacityById = [];
        foreach ($capacities as $capacity) {
            $store = $stores[$capacity->datastoreId] ?? null;
            if (null === $store
                || $store->backendType !== $capacity->backendType
                || isset($capacityById[$capacity->datastoreId])) {
                throw new InvalidArgumentException('The PBS capacity observations are invalid.');
            }
            $capacityById[$capacity->datastoreId] = $capacity;
        }
        ksort($capacityById, SORT_STRING);
        $this->capacities = array_values($capacityById);
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function isFullyAuthoritative(): bool
    {
        if (!$this->systemScope->isComplete() || !$this->datastoreScope->isComplete()) {
            return false;
        }
        if (null === $this->server->status) {
            return false;
        }
        $storeIds = array_column($this->datastores, 'id');
        $scopeIds = array_column($this->statusScopes, 'key');
        $capacityIds = array_column($this->capacities, 'datastoreId');
        if ($storeIds !== $scopeIds || $storeIds !== $capacityIds) {
            return false;
        }
        foreach ($this->statusScopes as $scope) {
            if (!$scope->isComplete()) {
                return false;
            }
        }

        return true;
    }

    /** @return 'succeeded'|'partial'|'failed' */
    public function overallStatus(): string
    {
        if ($this->isFullyAuthoritative()) {
            return 'succeeded';
        }
        if ('failed' === $this->systemScope->status->value
            && 'failed' === $this->datastoreScope->status->value
            && [] === $this->datastores) {
            return 'failed';
        }

        return 'partial';
    }
}
