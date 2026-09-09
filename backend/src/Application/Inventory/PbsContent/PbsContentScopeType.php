<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

enum PbsContentScopeType: string
{
    case Namespaces = 'pbs_namespaces';
    case Snapshots = 'pbs_snapshots';
}
