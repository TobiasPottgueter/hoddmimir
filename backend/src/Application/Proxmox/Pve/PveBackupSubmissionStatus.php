<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveBackupSubmissionStatus: string
{
    case Accepted = 'accepted';
    case Ambiguous = 'ambiguous';
}
