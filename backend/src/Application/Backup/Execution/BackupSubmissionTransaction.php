<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveUpid;

interface BackupSubmissionTransaction
{
    /** Read-only inspection that never creates a run. */
    public function inspectExistingSubmission(SubmitClaimedBackupCommand $command): ExistingSubmissionStatus;

    /** Atomically revalidates all gates and persists the run as awaiting_submission. */
    public function prepareAfterFullRevalidation(SubmitClaimedBackupCommand $command): SubmissionPreparation;

    public function recordAccepted(SubmitClaimedBackupCommand $command, PveUpid $upid): void;

    public function recordDefinitiveRejection(
        SubmitClaimedBackupCommand $command,
        PveBackupApiFailureCode $failure,
        DefinitiveBackupFailureNotice $notice,
    ): void;

    public function recordAmbiguous(SubmitClaimedBackupCommand $command, ?PveBackupApiFailureCode $failure): void;
}
