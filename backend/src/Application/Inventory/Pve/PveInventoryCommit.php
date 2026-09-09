<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use InvalidArgumentException;

final readonly class PveInventoryCommit
{
    /** @var list<PveNodeStorageScopeResult> */
    public array $nodeStorageScopes;

    /** @var list<PveStorageObservation> */
    public array $storages;

    /** @var list<PveNodeStorageStateObservation> */
    public array $nodeStorageStates;

    /**
     * @param list<PveNodeStorageScopeResult>       $nodeStorageScopes
     * @param list<PveStorageObservation>           $storages
     * @param list<PveNodeStorageStateObservation>  $nodeStorageStates
     */
    public function __construct(
        public PveCoreInventoryCommit $core,
        public PveCoreScopeResult $storageScope,
        array $nodeStorageScopes,
        array $storages,
        array $nodeStorageStates,
    ) {
        if (PveCoreScope::Storages !== $storageScope->scope) {
            throw new InvalidArgumentException('The PVE storage inventory scope is invalid.');
        }

        $knownNodes = array_fill_keys(array_column($core->nodes, 'name'), true);
        $scopesByNode = [];
        foreach ($nodeStorageScopes as $scope) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the runtime boundary promised by the PHPDoc)
            if (!$scope instanceof PveNodeStorageScopeResult
                || !isset($knownNodes[$scope->node])
                || isset($scopesByNode[$scope->node])) {
                throw new InvalidArgumentException('The PVE node-storage scopes are invalid.');
            }
            $scopesByNode[$scope->node] = $scope;
        }
        ksort($scopesByNode, SORT_STRING);
        $this->nodeStorageScopes = array_values($scopesByNode);

        $storagesById = [];
        foreach ($storages as $storage) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the runtime boundary promised by the PHPDoc)
            if (!$storage instanceof PveStorageObservation
                || !$storage->supportsBackup()
                || isset($storagesById[$storage->storageId])) {
                throw new InvalidArgumentException('The PVE storage observations are invalid.');
            }
            $storagesById[$storage->storageId] = $storage;
        }
        ksort($storagesById, SORT_STRING);
        $this->storages = array_values($storagesById);

        $statesByKey = [];
        foreach ($nodeStorageStates as $state) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the runtime boundary promised by the PHPDoc)
            if (!$state instanceof PveNodeStorageStateObservation
                || !isset($knownNodes[$state->node])
                || !isset($storagesById[$state->storageId])
                || $storagesById[$state->storageId]->disabled
                || !isset($scopesByNode[$state->node])
                || isset($statesByKey[$state->key()])) {
                throw new InvalidArgumentException('The PVE node-storage state observations are invalid.');
            }
            $statesByKey[$state->key()] = $state;
        }
        ksort($statesByKey, SORT_STRING);
        $this->nodeStorageStates = array_values($statesByKey);

        if ($this->isFullyAuthoritative()
            && count($knownNodes) !== count($this->nodeStorageScopes)) {
            throw new InvalidArgumentException('An authoritative PVE storage snapshot requires every topology node scope.');
        }
    }

    public function isFullyAuthoritative(): bool
    {
        if (!$this->core->isFullyAuthoritative() || !$this->storageScope->isComplete()) {
            return false;
        }
        foreach ($this->nodeStorageScopes as $scope) {
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
        if ('failed' === $this->core->overallStatus()
            && InventoryScopeStatus::Failed === $this->storageScope->status
            && [] === $this->storages
            && [] === $this->nodeStorageStates) {
            return 'failed';
        }

        return 'partial';
    }
}
