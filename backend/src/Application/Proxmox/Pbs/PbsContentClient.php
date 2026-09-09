<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

interface PbsContentClient
{
    public function permission(string $path): PbsEffectivePermission;

    /** @return list<PbsNamespace> */
    public function namespaces(PbsDatastoreId $datastore, int $maximumBodyBytes): array;

    /** @return list<PbsSnapshotObservation> */
    public function snapshots(
        PbsDatastoreId $datastore,
        PbsNamespace $namespace,
        int $maximumBodyBytes,
    ): array;
}
