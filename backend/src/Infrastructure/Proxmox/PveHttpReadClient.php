<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveBackupJobInventory;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveReadClient;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveNodeStorageStatusSet;
use App\Application\Proxmox\Pve\PveStorageConfigurationSet;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskNodeNameValidator;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use App\Infrastructure\Validation\AsciiPatternValidator;

final readonly class PveHttpReadClient implements PveReadClient
{
    private const NODE_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D';

    public function __construct(
        private PveApiTransport $transport,
        private PvePermissionReader $permissionReader,
        private PveClusterStatusReader $clusterStatusReader,
        private PveClusterResourcesReader $clusterResourcesReader,
        private PveStorageConfigurationReader $storageConfigurationReader,
        private PveNodeStorageStatusReader $nodeStorageStatusReader,
        private PveBackupJobReader $backupJobReader,
        private PveTaskPageReader $taskPageReader,
        private PveTaskStatusReader $taskStatusReader,
        private PveVersion $connectedVersion,
    ) {
    }

    public function version(): PveVersion
    {
        return $this->connectedVersion;
    }

    public function permissions(): PvePermissionAssessment
    {
        return $this->permissionReader->read($this->transport->get(['access', 'permissions']));
    }

    public function topology(): PveClusterTopology
    {
        return $this->clusterStatusReader->read($this->transport->get(['cluster', 'status']));
    }

    public function resources(): PveResourceInventory
    {
        return $this->clusterResourcesReader->read($this->transport->get(['cluster', 'resources']));
    }

    public function storageConfigurations(): PveStorageConfigurationSet
    {
        return $this->storageConfigurationReader->read($this->transport->get(['storage']));
    }

    public function nodeBackupStorages(string $node): PveNodeStorageStatusSet
    {
        $this->assertNode($node);

        return $this->nodeStorageStatusReader->read(
            $node,
            $this->transport->get(['nodes', $node, 'storage'], ['content' => 'backup']),
        );
    }

    public function backupJobs(): PveBackupJobInventory
    {
        return $this->backupJobReader->read($this->transport->get(['cluster', 'backup']));
    }

    public function backupTaskPage(string $node, PveTaskQuery $query): PveTaskPage
    {
        $this->assertTaskNode($node);

        return $this->taskPageReader->read(
            $node,
            $query,
            $this->transport->get(['nodes', $node, 'tasks'], $query->parameters()),
        );
    }

    public function backupTaskStatus(string $node, PveUpid $upid): PveTaskStatus
    {
        $this->assertTaskNode($node);
        if ($node !== $upid->node) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        return $this->taskStatusReader->read(
            $node,
            $upid,
            $this->transport->get(['nodes', $node, 'tasks', $upid->raw, 'status']),
        );
    }

    private function assertNode(string $node): void
    {
        if (!AsciiPatternValidator::matches(self::NODE_PATTERN, $node)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }
    }

    private function assertTaskNode(string $node): void
    {
        if (!PveTaskNodeNameValidator::isValid($node)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }
    }
}
