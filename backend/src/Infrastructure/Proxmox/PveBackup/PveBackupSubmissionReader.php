<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveUpid;
use InvalidArgumentException;

final readonly class PveBackupSubmissionReader
{
    public function read(PveBackupSubmission $submission, mixed $data): PveUpid
    {
        if (!is_string($data)) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
        try {
            $upid = PveUpid::parse($data);
        } catch (InvalidArgumentException) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
        if ($upid->node !== $submission->node || $upid->id !== (string) $submission->vmid) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }

        return $upid;
    }
}
