<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

interface ConnectionScanCatalog
{
    /** @return list<ConnectionScanTarget> */
    public function enabledTargets(): array;
}
