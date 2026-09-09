<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

interface BackupExecutionGate
{
    public function enabled(): bool;
}
