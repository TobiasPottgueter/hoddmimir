<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

interface BackupQueueStore
{
    /** Returns the binary request ID, idempotently. */
    public function promote(ShadowPromotion $promotion): string;

    public function claim(ClaimNextBackupCommand $command): ?ClaimedBackupRequest;

    public function finalize(FinalizeClaimedBackupCommand $command): bool;
}
