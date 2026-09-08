<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

/** Default for non-deployment runtimes. Production binds FileMaintenanceAccess. */
final readonly class UnrestrictedMaintenanceAccess implements MaintenanceAccess, MaintenancePermit
{
    public function phase(): MaintenancePhase { return MaintenancePhase::Open; }
    public function acquire(MaintenanceActivity $activity): MaintenancePermit { return $this; }
    public function release(): void {}
}
