<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

final readonly class ClaimNextBackup
{
    public function __construct(private BackupQueueStore $store)
    {
    }

    public function execute(ClaimNextBackupCommand $command): ?ClaimedBackupRequest
    {
        return $this->store->claim($command);
    }
}
