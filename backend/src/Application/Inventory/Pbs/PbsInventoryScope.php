<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

enum PbsInventoryScope: string
{
    case System = 'pbs_system';
    case Datastores = 'pbs_datastores';
    case DatastoreStatus = 'pbs_datastore_status';
}
