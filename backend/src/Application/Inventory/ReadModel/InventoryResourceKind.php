<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

enum InventoryResourceKind: string
{
    case PveCluster = 'pve_cluster';
    case PveNode = 'pve_node';
    case PveGuest = 'pve_guest';
    case PveStorage = 'pve_storage';
    case PbsServer = 'pbs_server';
    case PbsDatastore = 'pbs_datastore';
    case PbsNamespace = 'pbs_namespace';
    case PbsBackupGroup = 'pbs_backup_group';
    case PbsSnapshot = 'pbs_snapshot';

    public function permitsParentFilter(): bool
    {
        return self::PveCluster !== $this && self::PbsServer !== $this;
    }
}
