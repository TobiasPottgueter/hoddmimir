<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

enum InstallationBindingKind: string
{
    case PveCluster = 'pve_cluster';
    case PveStandalone = 'pve_standalone';
    case PbsInstance = 'pbs_instance';
    case PbsLegacyNode = 'pbs_legacy_node';
}
