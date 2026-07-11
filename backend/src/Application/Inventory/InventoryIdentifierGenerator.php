<?php

declare(strict_types=1);

namespace App\Application\Inventory;

interface InventoryIdentifierGenerator
{
    public function generate(): InventoryIdentifier;
}
