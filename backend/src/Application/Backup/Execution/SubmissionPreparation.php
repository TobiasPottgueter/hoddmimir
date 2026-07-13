<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use InvalidArgumentException;

final readonly class SubmissionPreparation
{
    public function __construct(
        public SubmissionPreparationStatus $status,
        public ?string $blockerCode = null,
        public ?PreparedBackupSubmission $submission = null,
    ) {
        if ((SubmissionPreparationStatus::Blocked === $status) !== (null !== $blockerCode)) {
            throw new InvalidArgumentException('Submission preparation is inconsistent.');
        }
        if ((SubmissionPreparationStatus::PreparedNow === $status) !== (null !== $submission)) {
            throw new InvalidArgumentException('Submission preparation is inconsistent.');
        }
        if (null !== $blockerCode) {
            if (1 !== \preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $blockerCode)) {
                throw new InvalidArgumentException('Submission preparation is inconsistent.');
            }
        }
    }
}
