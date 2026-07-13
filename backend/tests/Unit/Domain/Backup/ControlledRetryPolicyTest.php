<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Backup;

use App\Domain\Backup\ControlledRetryPolicy;
use App\Domain\Backup\SubmissionProvenance;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ControlledRetryPolicyTest extends TestCase
{
    #[DataProvider('delayProvider')]
    public function testDefinitiveFailuresRetryForeverWithCappedBackoff(
        int $attempt,
        int $expectedSeconds,
    ): void {
        $policy = new ControlledRetryPolicy();

        self::assertSame(
            $expectedSeconds,
            $policy->delayAfterAttempt($attempt, SubmissionProvenance::Accepted),
        );
        self::assertSame(
            $expectedSeconds,
            $policy->delayAfterAttempt($attempt, SubmissionProvenance::DefinitiveRejection),
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function delayProvider(): iterable
    {
        yield 'first failure' => [1, 60];
        yield 'second failure' => [2, 300];
        yield 'third failure' => [3, 900];
        yield 'fourth failure' => [4, 1_800];
        yield 'fifth failure' => [5, 3_600];
        yield 'sixth remains capped' => [6, 3_600];
        yield 'very late attempt remains capped' => [PHP_INT_MAX, 3_600];
    }

    #[DataProvider('forbiddenProvenanceProvider')]
    public function testAmbiguousAndUnsubmittedOutcomesCanNeverBeRetried(
        SubmissionProvenance $provenance,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        (new ControlledRetryPolicy())->delayAfterAttempt(1, $provenance);
    }

    /** @return iterable<string, array{SubmissionProvenance}> */
    public static function forbiddenProvenanceProvider(): iterable
    {
        yield 'ambiguous' => [SubmissionProvenance::Ambiguous];
        yield 'not submitted' => [SubmissionProvenance::NotSubmitted];
    }

    public function testNextAvailabilityUsesUtcAndTheCompletedAttempt(): void
    {
        $next = (new ControlledRetryPolicy())->nextAvailableAt(
            new DateTimeImmutable('2026-07-12T10:00:00.000000Z'),
            3,
            SubmissionProvenance::Accepted,
        );

        self::assertSame('2026-07-12T10:15:00.000000Z', $next->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function testInvalidAttemptFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ControlledRetryPolicy())->delayAfterAttempt(0, SubmissionProvenance::Accepted);
    }

    public function testNonUtcFailureTimestampFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ControlledRetryPolicy())->nextAvailableAt(
            new DateTimeImmutable('2026-07-12T12:00:00+02:00'),
            1,
            SubmissionProvenance::Accepted,
        );
    }
}
