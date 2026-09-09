<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveUpid;
use InvalidArgumentException;

final readonly class SubmissionExecutionResult
{
    public function __construct(
        public SubmissionExecutionStatus $status,
        public ?PveUpid $upid = null,
        public ?string $blockerCode = null,
    ) {
        if ((SubmissionExecutionStatus::Accepted === $status) !== (null !== $upid)
            || (SubmissionExecutionStatus::Blocked === $status) !== (null !== $blockerCode)) {
            throw new InvalidArgumentException('Submission execution result is inconsistent.');
        }
    }
}
