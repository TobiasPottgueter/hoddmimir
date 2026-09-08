<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

interface MaintenanceAccess
{
    public function phase(): MaintenancePhase;

    /** Hold the permit until every side effect of the operation has finished. */
    public function acquire(MaintenanceActivity $activity): ?MaintenancePermit;
}
