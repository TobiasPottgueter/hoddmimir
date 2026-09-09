<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

enum PveCoreScope: string
{
    case Topology = 'pve_topology';
    case Guests = 'pve_guests';
    case Storages = 'pve_storages';
    case NodeStorages = 'pve_node_storages';
}
