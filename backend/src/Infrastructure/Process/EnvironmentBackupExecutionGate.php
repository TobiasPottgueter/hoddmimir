<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Backup\Execution\BackupExecutionGate;

final readonly class EnvironmentBackupExecutionGate implements BackupExecutionGate
{
    public function __construct(private bool $executionEnabled) {}
    public function enabled(): bool { return $this->executionEnabled; }
}
