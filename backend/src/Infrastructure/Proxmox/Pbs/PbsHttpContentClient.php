<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsContentClient;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsNamespace;

final readonly class PbsHttpContentClient implements PbsContentClient
{
    public function __construct(
        private PbsApiTransport $transport,
        private PbsPermissionReader $permissionReader,
        private PbsNamespaceListReader $namespaceReader,
        private PbsSnapshotListReader $snapshotReader,
    ) {}

    public function permission(string $path): PbsEffectivePermission
    {
        return $this->permissionReader->read($this->transport->get(PbsRequest::permission($path)), $path);
    }

    public function namespaces(PbsDatastoreId $datastore, int $maximumBodyBytes): array
    {
        return $this->namespaceReader->read(
            $this->transport->get(PbsRequest::namespaces($datastore, $maximumBodyBytes)),
        );
    }

    public function snapshots(PbsDatastoreId $datastore, PbsNamespace $namespace, int $maximumBodyBytes): array
    {
        return $this->snapshotReader->read(
            $this->transport->get(PbsRequest::snapshots($datastore, $namespace, $maximumBodyBytes)),
            $datastore,
            $namespace,
        );
    }
}
