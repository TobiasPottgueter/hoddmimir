<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveReadClient;
use App\Application\Proxmox\Pve\PveReadConnector;

/**
 * One instance is bound to one configured endpoint and credential. The
 * version probe creates a fresh read session and is always its first call.
 */
final readonly class PveEndpointReadConnector implements PveReadConnector
{
    public function __construct(
        private PveApiTransport $transport,
        private PveVersionReader $versionReader,
        private PvePermissionReader $permissionReader,
        private PveClusterStatusReader $clusterStatusReader,
        private PveClusterResourcesReader $clusterResourcesReader,
        private PveStorageConfigurationReader $storageConfigurationReader,
        private PveNodeStorageStatusReader $nodeStorageStatusReader,
        private PveTaskPageReader $taskPageReader,
    ) {
    }

    public function connect(): PveReadClient
    {
        $version = $this->versionReader->read($this->transport->get(['version']));

        return new PveHttpReadClient(
            $this->transport,
            $this->permissionReader,
            $this->clusterStatusReader,
            $this->clusterResourcesReader,
            $this->storageConfigurationReader,
            $this->nodeStorageStatusReader,
            new PveBackupJobReader($version),
            $this->taskPageReader,
            new PveTaskStatusReader($version),
            $version,
        );
    }
}
