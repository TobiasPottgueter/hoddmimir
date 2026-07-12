<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

interface PveBackupClient
{
    public function submit(PveBackupSubmission $submission): PveBackupSubmissionResult;

    public function taskStatus(PveUpid $upid): PveTaskStatus;

    public function taskLog(PveUpid $upid, PveTaskLogQuery $query): PveTaskLogPage;

    public function stopTask(PveUpid $upid): PveTaskStopResult;
}
