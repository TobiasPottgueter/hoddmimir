<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scheduler;

use App\Domain\Policy\PolicyPriority;
use App\Domain\Scheduler\BackupCandidateWinnerSelector;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\EligibleBackupCandidate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupCandidateWinnerSelectorTest extends TestCase
{
    public function testSelectsHighestReasonBeforePolicyPriority(): void
    {
        $lowerPriorityPolicy = $this->candidate('a', 'a', 'a', BackupReason::NeverBackedUp, 0);
        $higherPriorityPolicy = $this->candidate('b', 'b', 'b', BackupReason::MaxAge, 1_000);

        $selection = (new BackupCandidateWinnerSelector())->select([$higherPriorityPolicy, $lowerPriorityPolicy]);

        self::assertSame($lowerPriorityPolicy, $selection->winner);
        self::assertSame([$higherPriorityPolicy], $selection->discarded);
    }

    public function testSelectsHigherPolicyPriorityForEqualReason(): void
    {
        $lower = $this->candidate('a', 'a', 'a', BackupReason::MaxAge, 99);
        $higher = $this->candidate('b', 'b', 'b', BackupReason::MaxAge, 100);

        self::assertSame(
            $higher,
            (new BackupCandidateWinnerSelector())->select([$lower, $higher])->winner,
        );
    }

    public function testIdenticalCandidateComparisonIsStable(): void
    {
        $candidate = $this->candidate('a', 'a', 'a', BackupReason::MaxAge, 100);
        $selection = (new BackupCandidateWinnerSelector())->select([$candidate, $candidate]);

        self::assertSame($candidate, $selection->winner);
        self::assertSame([$candidate], $selection->discarded);
    }

    public function testComparisonBranchesAreDirectlyObservableOutsideTheInternalSortCallback(): void
    {
        $method = new \ReflectionMethod(BackupCandidateWinnerSelector::class, 'compare');
        $cases = [
            [$this->candidate('a', 'a', 'a', BackupReason::NeverBackedUp, 1), $this->candidate('b', 'b', 'b', BackupReason::MaxAge, 1)],
            [$this->candidate('a', 'a', 'a', BackupReason::MaxAge, 2), $this->candidate('b', 'b', 'b', BackupReason::MaxAge, 1)],
            [$this->candidate('a', 'a', 'a', BackupReason::MaxAge, 1), $this->candidate('b', 'b', 'b', BackupReason::MaxAge, 1)],
            [$this->candidate('a', 'a', 'a', BackupReason::MaxAge, 1), $this->candidate('b', 'a', 'b', BackupReason::MaxAge, 1)],
            [$this->candidate('a', 'a', 'a', BackupReason::MaxAge, 1), $this->candidate('b', 'a', 'a', BackupReason::MaxAge, 1)],
        ];

        foreach ($cases as [$left, $right]) {
            self::assertNotSame(0, $method->invoke(null, $left, $right));
            self::assertNotSame(0, $method->invoke(null, $right, $left));
        }
        $same = $this->candidate('a', 'a', 'a', BackupReason::MaxAge, 1);
        self::assertSame(0, $method->invoke(null, $same, $same));
    }

    #[DataProvider('stableTieBreakProvider')]
    public function testUsesStableAscendingIdsAfterBusinessPriorities(
        EligibleBackupCandidate $expected,
        EligibleBackupCandidate $other,
    ): void {
        $selector = new BackupCandidateWinnerSelector();

        self::assertSame($expected, $selector->select([$other, $expected])->winner);
        self::assertSame($expected, $selector->select([$expected, $other])->winner);
    }

    /** @return iterable<string, array{EligibleBackupCandidate, EligibleBackupCandidate}> */
    public static function stableTieBreakProvider(): iterable
    {
        $self = new self('stableTieBreakProvider');
        yield 'policy ID' => [
            $self->candidate('a', 'b', 'b', BackupReason::BytesWritten, 10),
            $self->candidate('b', 'c', 'a', BackupReason::BytesWritten, 10),
        ];
        yield 'target ID' => [
            $self->candidate('b', 'a', 'a', BackupReason::BytesWritten, 10),
            $self->candidate('a', 'a', 'b', BackupReason::BytesWritten, 10),
        ];
        yield 'decision ID' => [
            $self->candidate('a', 'a', 'a', BackupReason::BytesWritten, 10),
            $self->candidate('b', 'a', 'a', BackupReason::BytesWritten, 10),
        ];
    }

    /** @param list<mixed> $candidates */
    #[DataProvider('invalidCandidateListProvider')]
    public function testRejectsInvalidCandidateLists(array $candidates): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BackupCandidateWinnerSelector())->select($candidates);
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function invalidCandidateListProvider(): iterable
    {
        $candidate = (new self('invalidCandidateListProvider'))->candidate(
            'a',
            'a',
            'a',
            BackupReason::MaxAge,
            10,
        );
        yield 'empty' => [[]];
        yield 'first value has wrong type' => [['invalid']];
        yield 'later value has wrong type' => [[$candidate, 'invalid']];
        yield 'different guest' => [[$candidate, new EligibleBackupCandidate(
            str_repeat('b', 16),
            str_repeat('x', 16),
            str_repeat('a', 16),
            str_repeat('a', 16),
            BackupReason::MaxAge,
            new PolicyPriority(10),
        )]];
    }

    #[DataProvider('invalidIdProvider')]
    public function testCandidateRejectsInvalidIds(string $field, string $value): void
    {
        $ids = [
            'decision' => str_repeat('d', 16),
            'guest' => str_repeat('g', 16),
            'policy' => str_repeat('p', 16),
            'target' => str_repeat('t', 16),
        ];
        $ids[$field] = $value;
        $this->expectException(InvalidArgumentException::class);
        new EligibleBackupCandidate(
            $ids['decision'],
            $ids['guest'],
            $ids['policy'],
            $ids['target'],
            BackupReason::MaxAge,
            new PolicyPriority(10),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIdProvider(): iterable
    {
        foreach (['decision', 'guest', 'policy', 'target'] as $field) {
            yield $field.' short' => [$field, str_repeat('x', 15)];
            yield $field.' long' => [$field, str_repeat('x', 17)];
        }
    }

    public function testAutomaticCandidateRejectsManualReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->candidate('a', 'a', 'a', BackupReason::Manual, 10);
    }

    private function candidate(
        string $decision,
        string $policy,
        string $target,
        BackupReason $reason,
        int $priority,
    ): EligibleBackupCandidate {
        return new EligibleBackupCandidate(
            str_repeat($decision, 16),
            str_repeat('g', 16),
            str_repeat($policy, 16),
            str_repeat($target, 16),
            $reason,
            new PolicyPriority($priority),
        );
    }
}
