<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

interface PveReadClient
{
    public function version(): PveVersion;

    public function permissions(): PvePermissionAssessment;

    public function topology(): PveClusterTopology;

    public function resources(): PveResourceInventory;

    public function storageConfigurations(): PveStorageConfigurationSet;

    public function nodeBackupStorages(string $node): PveNodeStorageStatusSet;

    public function backupJobs(): PveBackupJobInventory;

    public function backupTaskPage(string $node, PveTaskQuery $query): PveTaskPage;

    public function backupTaskStatus(string $node, PveUpid $upid): PveTaskStatus;
}
