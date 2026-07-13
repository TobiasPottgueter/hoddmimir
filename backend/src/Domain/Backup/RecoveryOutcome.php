<?php

declare(strict_types=1);

namespace App\Domain\Backup;

final readonly class RecoveryOutcome
{
    private function __construct(
        public RecoveryOutcomeKind $kind,
        public ?TaskUpid $upid,
    ) {
    }

    public static function matched(TaskUpid $upid): self
    {
        return new self(RecoveryOutcomeKind::Matched, $upid);
    }

    public static function provenNotStarted(): self
    {
        return new self(RecoveryOutcomeKind::ProvenNotStarted, null);
    }

    public static function inconclusive(): self
    {
        return new self(RecoveryOutcomeKind::Inconclusive, null);
    }

    public static function multipleMatches(): self
    {
        return new self(RecoveryOutcomeKind::MultipleMatches, null);
    }
}
