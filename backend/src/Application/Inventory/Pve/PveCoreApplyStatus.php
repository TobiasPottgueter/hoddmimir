<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

enum PveCoreApplyStatus: string
{
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
