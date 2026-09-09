<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Proxmox\Pbs\PbsContentClient;
use App\Application\Proxmox\Pbs\PbsContentLimits;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsReadFailure;

final readonly class ReadPbsContent
{
    public function __construct(private PbsContentLimits $limits) {}

    /** @param list<PbsDatastoreId> $datastores */
    public function read(
        PbsContentClient $client,
        array $datastores,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsContentSnapshot {
        $namespaces = [];
        $snapshots = [];
        $scopes = [];
        $totalSnapshots = 0;
        foreach ($datastores as $index => $datastore) {
            $checkpoint->checkpoint();
            if ($index >= $this->limits->maximumDatastores) {
                $scopes[] = new PbsContentScopeResult(
                    PbsContentScopeType::Namespaces, $datastore, null,
                    PbsContentScopeStatus::Partial, 0, 'datastore_limit_exceeded',
                );
                continue;
            }
            $path = '/datastore/'.$datastore->value;
            try {
                $startPermission = $client->permission($path);
                $rows = $client->namespaces($datastore, $this->limits->namespaceBodyBytes);
                $checkpoint->checkpoint();
                $endPermission = $client->permission($path);
            } catch (PbsReadFailure) {
                $scopes[] = new PbsContentScopeResult(
                    PbsContentScopeType::Namespaces, $datastore, null,
                    PbsContentScopeStatus::Failed, 0, 'namespace_read_failed',
                );
                continue;
            }
            $limited = count($rows) > $this->limits->maximumNamespacesPerDatastore;
            if ($limited) {
                $rows = array_slice($rows, 0, $this->limits->maximumNamespacesPerDatastore);
            }
            $aclComplete = $startPermission->grants('Datastore.Audit')
                && $startPermission->propagates('Datastore.Audit')
                && $endPermission->grants('Datastore.Audit')
                && $endPermission->propagates('Datastore.Audit');
            $namespaceStatus = $limited || !$aclComplete
                ? PbsContentScopeStatus::Partial : PbsContentScopeStatus::Complete;
            $scopes[] = new PbsContentScopeResult(
                PbsContentScopeType::Namespaces,
                $datastore,
                null,
                $namespaceStatus,
                count($rows),
                $limited ? 'namespace_limit_exceeded' : ($aclComplete ? null : 'acl_incomplete'),
            );
            foreach ($rows as $namespace) {
                $observation = new PbsNamespaceObservation($datastore, $namespace);
                $namespaces[$observation->key()] = $observation;
                $checkpoint->checkpoint();
                if ($totalSnapshots >= $this->limits->maximumTotalSnapshots) {
                    $scopes[] = new PbsContentScopeResult(
                        PbsContentScopeType::Snapshots, $datastore, $namespace,
                        PbsContentScopeStatus::Partial, 0, 'total_snapshot_limit_exceeded',
                    );
                    continue;
                }
                $namespacePath = $path.($namespace->isRoot() ? '' : '/'.$namespace->value);
                $before = null;
                $after = null;
                try {
                    $before = $client->permission($namespacePath);
                } catch (PbsReadFailure|\InvalidArgumentException) {
                    // Valid PBS namespaces can exceed the ACL endpoint's 128-byte path schema.
                    // Snapshot positives remain readable, but absence is not authoritative.
                }
                try {
                    $snapshotRows = $client->snapshots(
                        $datastore, $namespace, $this->limits->snapshotBodyBytes,
                    );
                    $checkpoint->checkpoint();
                } catch (PbsReadFailure|\InvalidArgumentException) {
                    $scopes[] = new PbsContentScopeResult(
                        PbsContentScopeType::Snapshots, $datastore, $namespace,
                        PbsContentScopeStatus::Failed, 0, 'snapshot_read_failed',
                    );
                    continue;
                }
                if (null !== $before) {
                    try {
                        $after = $client->permission($namespacePath);
                    } catch (PbsReadFailure|\InvalidArgumentException) {
                        // The successful snapshot rows are still positive evidence.
                    }
                }
                $remaining = $this->limits->maximumTotalSnapshots - $totalSnapshots;
                $rowLimited = count($snapshotRows) > $this->limits->maximumSnapshotsPerNamespace
                    || count($snapshotRows) > $remaining;
                $maximumForScope = $this->limits->maximumSnapshotsPerNamespace;
                if ($remaining < $maximumForScope) {
                    $maximumForScope = $remaining;
                }
                $snapshotRows = array_slice(
                    $snapshotRows,
                    0,
                    $maximumForScope,
                );
                $audit = null !== $before && null !== $after
                    && $before->grants('Datastore.Audit') && $after->grants('Datastore.Audit');
                foreach ($snapshotRows as $snapshot) {
                    $snapshots[$datastore->value."\0".$snapshot->key()] = $snapshot;
                }
                $totalSnapshots += count($snapshotRows);
                $scopes[] = new PbsContentScopeResult(
                    PbsContentScopeType::Snapshots,
                    $datastore,
                    $namespace,
                    $rowLimited || !$audit ? PbsContentScopeStatus::Partial : PbsContentScopeStatus::Complete,
                    count($snapshotRows),
                    $rowLimited ? 'snapshot_limit_exceeded' : ($audit ? null : 'acl_incomplete'),
                );
            }
        }

        return new PbsContentSnapshot(array_values($namespaces), array_values($snapshots), $scopes);
    }
}
