<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveBackupJobResponseContract: string
{
    case BaselineIdOnly = 'baseline_id_only';
    case SelectedTypedFields = 'selected_typed_fields';
}
