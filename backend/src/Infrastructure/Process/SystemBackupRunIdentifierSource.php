<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use App\Application\Backup\Worker\BackupRunIdentifierSource;

final readonly class SystemBackupRunIdentifierSource implements BackupRunIdentifierSource
{
    public function next(): string
    {
        return random_bytes(16);
    }
}
