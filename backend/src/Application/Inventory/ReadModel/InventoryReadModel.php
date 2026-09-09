<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

interface InventoryReadModel
{
    public function overview(): InventoryOverview;

    public function resources(InventoryResourceQuery $query): ReadPage;
}
