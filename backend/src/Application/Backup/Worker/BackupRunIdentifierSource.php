<?php

declare(strict_types=1);

namespace App\Application\Backup\Worker;

interface BackupRunIdentifierSource
{
    public function next(): string;
}
