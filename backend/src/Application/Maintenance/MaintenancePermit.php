<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

interface MaintenancePermit
{
    public function release(): void;
}
