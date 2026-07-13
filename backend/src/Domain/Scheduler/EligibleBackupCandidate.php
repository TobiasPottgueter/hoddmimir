<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use App\Domain\Policy\PolicyPriority;
use InvalidArgumentException;

final readonly class EligibleBackupCandidate
{
    public function __construct(
        public string $decisionId,
        public string $guestId,
        public string $policyId,
        public string $targetId,
        public BackupReason $reason,
        public PolicyPriority $policyPriority,
    ) {
        foreach ([$decisionId, $guestId, $policyId, $targetId] as $id) {
            if (16 !== strlen($id)) {
                throw new InvalidArgumentException('An eligible backup candidate ID must contain 16 bytes.');
            }
        }
        if (BackupReason::Manual === $reason) {
            throw new InvalidArgumentException('A shadow winner candidate must use an automatic reason.');
        }
    }

    public function reasonPriority(): Priority
    {
        return ReasonPriority::expected($this->reason);
    }
}
