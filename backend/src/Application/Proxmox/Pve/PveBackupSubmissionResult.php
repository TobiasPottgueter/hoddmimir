<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveBackupSubmissionResult
{
    public function __construct(
        public PveBackupSubmissionStatus $status,
        public ?PveUpid $upid,
    ) {
        if ((PveBackupSubmissionStatus::Accepted === $status) !== (null !== $upid)) {
            throw new InvalidArgumentException('The PVE backup submission result is inconsistent.');
        }
    }

    public static function accepted(PveUpid $upid): self
    {
        return new self(PveBackupSubmissionStatus::Accepted, $upid);
    }

    public static function ambiguous(): self
    {
        return new self(PveBackupSubmissionStatus::Ambiguous, null);
    }
}
