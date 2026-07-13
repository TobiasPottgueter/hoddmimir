<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

final readonly class BackupCandidateSelection
{
    /** @param list<EligibleBackupCandidate> $discarded */
    public function __construct(
        public EligibleBackupCandidate $winner,
        public array $discarded,
    ) {
    }
}
