<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

final readonly class FinalizeClaimedBackup
{
    public function __construct(private BackupQueueStore $store)
    {
    }

    public function execute(FinalizeClaimedBackupCommand $command): bool
    {
        return $this->store->finalize($command);
    }
}
