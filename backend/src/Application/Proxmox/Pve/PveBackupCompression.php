<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveBackupCompression: string
{
    case None = '0';
    case Gzip = 'gzip';
    case Lzo = 'lzo';
    case Zstd = 'zstd';
}
