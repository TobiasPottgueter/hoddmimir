<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

enum PveBackupWriteTransportStatus: string
{
    case Responded = 'responded';
    case Ambiguous = 'ambiguous';
}
