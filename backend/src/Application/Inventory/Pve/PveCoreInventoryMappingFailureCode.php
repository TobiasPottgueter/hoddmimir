<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

enum PveCoreInventoryMappingFailureCode: string
{
    case NonPveRead = 'non_pve_read';
    case BindingMismatch = 'binding_mismatch';
}
