<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

enum PveCoreInventoryConflictCode: string
{
    case ConnectionChanged = 'connection_changed';
    case InvariantViolation = 'invariant_violation';
}
