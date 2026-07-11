<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

interface InstallationBindingCatalog
{
    public function bindingFor(ConnectionId $connectionId): ?InstallationBinding;
}
