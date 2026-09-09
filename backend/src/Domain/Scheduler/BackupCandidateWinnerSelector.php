<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use InvalidArgumentException;

final readonly class BackupCandidateWinnerSelector
{
    /** @param list<mixed> $candidates */
    public function select(array $candidates): BackupCandidateSelection
    {
        if ([] === $candidates || !$candidates[0] instanceof EligibleBackupCandidate) {
            throw new InvalidArgumentException('Winner selection requires at least one eligible candidate.');
        }
        $guestId = $candidates[0]->guestId;
        /** @var list<EligibleBackupCandidate> $validated */
        $validated = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof EligibleBackupCandidate
                || !hash_equals($guestId, $candidate->guestId)
            ) {
                throw new InvalidArgumentException('Winner selection requires candidates for exactly one guest.');
            }
            $validated[] = $candidate;
        }
        usort($validated, self::compare(...));

        return new BackupCandidateSelection($validated[0], array_slice($validated, 1));
    }

    private static function compare(EligibleBackupCandidate $left, EligibleBackupCandidate $right): int
    {
        $reason = $right->reasonPriority()->value <=> $left->reasonPriority()->value;
        if (0 !== $reason) {
            return $reason;
        }
        $policyPriority = $right->policyPriority->value <=> $left->policyPriority->value;
        if (0 !== $policyPriority) {
            return $policyPriority;
        }
        foreach ([
            [$left->policyId, $right->policyId],
            [$left->targetId, $right->targetId],
            [$left->decisionId, $right->decisionId],
        ] as [$leftId, $rightId]) {
            $stable = strcmp($leftId, $rightId);
            if (0 !== $stable) {
                return $stable;
            }
        }

        return 0;
    }
}
