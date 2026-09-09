<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use InvalidArgumentException;

final readonly class EligibilityEvaluator
{
    /** @param non-empty-list<GateResult> $gates */
    public function evaluate(array $gates, ?ReasonPriority $reasonPriority): SchedulerEligibility
    {
        // @phpstan-ignore identical.alwaysFalse (enforce the PHPDoc runtime boundary)
        if ([] === $gates) {
            throw new InvalidArgumentException('Eligibility requires at least one explicit gate.');
        }

        $failures = [];
        foreach ($gates as $gate) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$gate instanceof GateResult) {
                throw new InvalidArgumentException('Eligibility gates are invalid.');
            }
            if (!$gate->passed) {
                $failures[] = $gate;
            }
        }

        if ([] === $failures) {
            return new SchedulerEligibility(
                null === $reasonPriority ? DecisionOutcome::NotDue : DecisionOutcome::Eligible,
                $reasonPriority,
            );
        }

        $onlyDuplicateFailures = true;
        foreach ($failures as $failure) {
            if (!(
                (GateCode::ActiveRequestAbsent === $failure->code
                    && GateDetailCode::ActiveRequestExists === $failure->detailCode)
                || (GateCode::HigherRankedCandidateAbsent === $failure->code
                    && GateDetailCode::HigherRankedCandidate === $failure->detailCode)
            )) {
                $onlyDuplicateFailures = false;
            }
        }

        return new SchedulerEligibility(
            $onlyDuplicateFailures ? DecisionOutcome::Deduplicated : DecisionOutcome::Blocked,
            $reasonPriority,
        );
    }
}
