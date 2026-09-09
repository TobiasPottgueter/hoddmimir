<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

enum PbsInventoryApplyStatus: string
{
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
