<?php

declare(strict_types=1);

namespace App\Application\Policy\ReadModel;

interface PolicyReadModel
{
    public function policies(PolicyListQuery $query): PolicyPage;

    public function selection(PolicySelectionQuery $query): PolicySelectionPage;
}
