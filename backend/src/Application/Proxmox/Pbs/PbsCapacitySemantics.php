<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsCapacitySemantics: string
{
    case DatastoreFilesystem = 'datastore_filesystem';
    case LocalCache = 'local_cache';
}
