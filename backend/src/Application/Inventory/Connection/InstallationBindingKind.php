<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

enum InstallationBindingKind: string
{
    case PveCluster = 'pve_cluster';
    case PveStandalone = 'pve_standalone';
    case Pbs4Instance = 'pbs4_instance';
    case Pbs3Node = 'pbs3_node';
}
