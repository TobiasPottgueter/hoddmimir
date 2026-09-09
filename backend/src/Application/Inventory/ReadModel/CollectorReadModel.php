<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

interface CollectorReadModel
{
    public function status(): CollectorStatus;

    public function runs(PageRequest $page): ReadPage;

    public function scopes(CollectorScopeQuery $query): ReadPage;
}
