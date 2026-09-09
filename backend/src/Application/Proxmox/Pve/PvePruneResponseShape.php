<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PvePruneResponseShape: string
{
    case LegacyStringOrObject = 'legacy_string_or_object';
    case Object = 'object';
}
