<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Backup\Execution\BackupExecutionGate;

final readonly class EnvironmentBackupExecutionGate implements BackupExecutionGate
{
    public function __construct(private bool $executionEnabled,
        private \App\Application\Maintenance\MaintenanceAccess $maintenance = new \App\Application\Maintenance\UnrestrictedMaintenanceAccess(),
    ) {}
    public function enabled(): bool { return $this->executionEnabled && \App\Application\Maintenance\MaintenancePhase::Open === $this->maintenance->phase(); }
}
