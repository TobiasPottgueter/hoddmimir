<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Proxmox\Pbs\PbsSnapshotObservation;
use InvalidArgumentException;

final readonly class PbsContentSnapshot
{
    /** @var list<PbsNamespaceObservation> */ public array $namespaces;
    /** @var list<PbsSnapshotObservation> */ public array $snapshots;
    /** @var list<PbsContentScopeResult> */ public array $scopes;

    /**
     * @param list<PbsNamespaceObservation> $namespaces
     * @param list<PbsSnapshotObservation> $snapshots
     * @param list<PbsContentScopeResult> $scopes
     */
    public function __construct(array $namespaces, array $snapshots, array $scopes)
    {
        $namespaceMap = [];
        foreach ($namespaces as $namespace) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$namespace instanceof PbsNamespaceObservation || isset($namespaceMap[$namespace->key()])) {
                throw new InvalidArgumentException('The PBS content namespaces are invalid.');
            }
            $namespaceMap[$namespace->key()] = $namespace;
        }
        ksort($namespaceMap, SORT_STRING);
        $this->namespaces = array_values($namespaceMap);

        $snapshotMap = [];
        $owners = [];
        foreach ($snapshots as $snapshot) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$snapshot instanceof PbsSnapshotObservation
                || !isset($namespaceMap[$snapshot->datastore->value."\0".$snapshot->namespace->value])
                || isset($snapshotMap[$snapshot->key()])) {
                throw new InvalidArgumentException('The PBS content snapshots are invalid.');
            }
            $groupKey = $snapshot->groupKey();
            if (null !== $snapshot->owner && isset($owners[$groupKey])
                && !hash_equals($owners[$groupKey], $snapshot->owner)) {
                throw new InvalidArgumentException('The PBS content group owners conflict.');
            }
            if (null !== $snapshot->owner) {
                $owners[$groupKey] = $snapshot->owner;
            }
            $snapshotMap[$snapshot->key()] = $snapshot;
        }
        ksort($snapshotMap, SORT_STRING);
        $this->snapshots = array_values($snapshotMap);

        $scopeMap = [];
        foreach ($scopes as $scope) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$scope instanceof PbsContentScopeResult) {
                throw new InvalidArgumentException('The PBS content scopes are invalid.');
            }
            $key = $scope->type->value."\0".$scope->key();
            if (isset($scopeMap[$key])) {
                throw new InvalidArgumentException('The PBS content scopes contain duplicates.');
            }
            $scopeMap[$key] = $scope;
        }
        ksort($scopeMap, SORT_STRING);
        $this->scopes = array_values($scopeMap);
        if ([] === $this->scopes && ([] !== $this->namespaces || [] !== $this->snapshots)) {
            throw new InvalidArgumentException('PBS content without datastores cannot contain observations.');
        }
    }

    public function status(): PbsContentRunStatus
    {
        if ([] === $this->scopes) {
            return PbsContentRunStatus::Succeeded;
        }
        $usable = false;
        $complete = true;
        foreach ($this->scopes as $scope) {
            if (PbsContentScopeStatus::Failed !== $scope->status) {
                $usable = true;
            }
            if (PbsContentScopeStatus::Complete !== $scope->status) {
                $complete = false;
            }
        }
        if (!$usable) {
            return PbsContentRunStatus::Failed;
        }
        return $complete ? PbsContentRunStatus::Succeeded : PbsContentRunStatus::Partial;
    }
}
