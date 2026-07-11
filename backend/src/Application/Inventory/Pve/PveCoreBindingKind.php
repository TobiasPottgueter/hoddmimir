<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

enum PveCoreBindingKind: string
{
    case Cluster = 'pve_cluster';
    case Standalone = 'pve_standalone';
}
