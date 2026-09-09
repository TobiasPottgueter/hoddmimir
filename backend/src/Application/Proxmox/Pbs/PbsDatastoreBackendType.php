<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsDatastoreBackendType: string
{
    case Filesystem = 'filesystem';
    case S3 = 's3';
}
