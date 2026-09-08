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

    /** Only complete task searches authorize a distinct attempt, never a transport retry. */
    public function permitsNewAttempt(): bool
    {
        return \in_array($this->kind, [RecoveryOutcomeKind::ProvenNotStarted, RecoveryOutcomeKind::MultipleMatches], true);
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
