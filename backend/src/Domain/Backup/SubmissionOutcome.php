<?php

declare(strict_types=1);

namespace App\Domain\Backup;

final readonly class SubmissionOutcome
{
    private function __construct(
        public SubmissionOutcomeKind $kind,
        public ?TaskUpid $upid,
    ) {
    }

    public static function accepted(TaskUpid $upid): self
    {
        return new self(SubmissionOutcomeKind::Accepted, $upid);
    }

    public static function rejected(): self
    {
        return new self(SubmissionOutcomeKind::Rejected, null);
    }

    public static function ambiguous(): self
    {
        return new self(SubmissionOutcomeKind::Ambiguous, null);
    }
}
