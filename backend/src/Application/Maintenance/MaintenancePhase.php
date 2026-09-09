<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

enum MaintenancePhase: string
{
    case Open = 'open';
    case Draining = 'draining';
    case Frozen = 'frozen';

    public function allows(MaintenanceActivity $activity): bool
    {
        return self::Open === $this || (self::Draining === $this && MaintenanceActivity::MonitorBackups === $activity);
    }
}
