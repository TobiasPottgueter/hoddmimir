<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scheduler;

use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\EligibilityEvaluator;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateResult;
use App\Domain\Scheduler\GateScope;
use App\Domain\Scheduler\GateSubjectId;
use App\Domain\Scheduler\Priority;
use App\Domain\Scheduler\ReasonPriority;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EligibilityEvaluatorTest extends TestCase
{
    public function testPassedGatesAreEligibleOnlyWhenAReasonIsDue(): void
    {
        $evaluator = new EligibilityEvaluator();
        $passed = [$this->gate(GateCode::GuestActive, true, GateDetailCode::Passed)];
        $reason = new ReasonPriority(BackupReason::NeverBackedUp, Priority::NeverBackedUp);

        $eligible = $evaluator->evaluate($passed, $reason);
        $notDue = $evaluator->evaluate($passed, null);

        self::assertSame(DecisionOutcome::Eligible, $eligible->outcome);
        self::assertSame($reason, $eligible->reasonPriority);
        self::assertSame(DecisionOutcome::NotDue, $notDue->outcome);
        self::assertNull($notDue->reasonPriority);
    }

    public function testOnlyAnExistingActiveRequestProducesDeduplicated(): void
    {
        $evaluator = new EligibilityEvaluator();
        $duplicate = $this->gate(
            GateCode::ActiveRequestAbsent,
            false,
            GateDetailCode::ActiveRequestExists,
        );
        $reason = new ReasonPriority(BackupReason::MaxAge, Priority::MaxAge);

        self::assertSame(
            DecisionOutcome::Deduplicated,
            $evaluator->evaluate([$duplicate], $reason)->outcome,
        );
        self::assertSame(
            DecisionOutcome::Deduplicated,
            $evaluator->evaluate([$duplicate, $duplicate], null)->outcome,
        );
    }

    public function testEverySafetyFailureWinsAgainstDuplicateAndDueReason(): void
    {
        $evaluation = (new EligibilityEvaluator())->evaluate([
            $this->gate(GateCode::ActiveRequestAbsent, false, GateDetailCode::ActiveRequestExists),
            $this->gate(GateCode::CapacityFresh, false, GateDetailCode::Stale),
        ], new ReasonPriority(BackupReason::BytesWritten, Priority::BytesWritten));

        self::assertSame(DecisionOutcome::Blocked, $evaluation->outcome);
        self::assertSame(BackupReason::BytesWritten, $evaluation->reasonPriority?->reason);
    }

    public function testGateCollectionFailsClosedAtRuntimeBoundaries(): void
    {
        $evaluator = new EligibilityEvaluator();
        foreach ([[], ['not-a-gate']] as $gates) {
            try {
                /** @phpstan-ignore argument.type (exercise the runtime boundary) */
                $unused = $evaluator->evaluate($gates, null);
                self::fail('Invalid eligibility gates were accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function gate(GateCode $code, bool $passed, GateDetailCode $detail): GateResult
    {
        return new GateResult(
            $code,
            $passed,
            GateScope::Guest,
            new GateSubjectId(str_repeat('g', 16)),
            null,
            $detail,
        );
    }
}
