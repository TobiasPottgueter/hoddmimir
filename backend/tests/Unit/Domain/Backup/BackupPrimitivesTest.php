<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Backup;

use App\Domain\Backup\BackupRequestId;
use App\Domain\Backup\BackupRequestState;
use App\Domain\Backup\BackupRunId;
use App\Domain\Backup\BackupRunState;
use App\Domain\Backup\ClaimAuthority;
use App\Domain\Backup\ClaimFence;
use App\Domain\Backup\ClaimLease;
use App\Domain\Backup\ClaimToken;
use App\Domain\Backup\RecoveryOutcome;
use App\Domain\Backup\RecoveryOutcomeKind;
use App\Domain\Backup\SubmissionOutcome;
use App\Domain\Backup\SubmissionOutcomeKind;
use App\Domain\Backup\TaskUpid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupPrimitivesTest extends TestCase
{
    public function testBinaryIdentifiersAndClaimTokensRemainOpaqueAndComparable(): void
    {
        $one = str_repeat('a', 16);
        $two = str_repeat('b', 16);
        $request = new BackupRequestId($one);
        $run = new BackupRunId($one);
        $token = new ClaimToken($one);

        self::assertSame($one, $request->binary());
        self::assertTrue($request->equals(new BackupRequestId($one)));
        self::assertFalse($request->equals(new BackupRequestId($two)));
        self::assertSame($one, $run->binary());
        self::assertTrue($run->equals(new BackupRunId($one)));
        self::assertFalse($run->equals(new BackupRunId($two)));
        self::assertSame($one, $token->binary());
        self::assertTrue($token->equals(new ClaimToken($one)));
        self::assertFalse($token->equals(new ClaimToken($two)));
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function invalidOpaqueIdentifiers(): iterable
    {
        foreach ([BackupRequestId::class, BackupRunId::class, ClaimToken::class] as $class) {
            yield $class.' empty' => [$class, ''];
            yield $class.' short' => [$class, str_repeat('x', 15)];
            yield $class.' long' => [$class, str_repeat('x', 17)];
        }
    }

    #[DataProvider('invalidOpaqueIdentifiers')]
    public function testOpaqueIdentifiersRejectInvalidLengths(string $class, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new $class($value);
    }

    public function testFencesArePositiveMonotoneAndBounded(): void
    {
        $first = new ClaimFence(1);
        self::assertTrue($first->isAfter(null));
        self::assertFalse($first->isAfter(new ClaimFence(1)));
        self::assertTrue($first->next()->isAfter($first));

        try {
            new ClaimFence(0);
            self::fail('A zero fence was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(OverflowException::class);
        (new ClaimFence(PHP_INT_MAX))->next();
    }

    public function testLeaseBoundariesRenewalAndAuthorityAreClosed(): void
    {
        $issued = $this->at('2026-07-12 10:00:00.000000');
        $expires = $this->at('2026-07-12 10:01:00.000000');
        $authority = $this->authority(1, 'a');
        $lease = new ClaimLease($authority, $issued, $expires);

        self::assertTrue($lease->isActiveAt($issued));
        self::assertTrue($lease->isActiveAt($this->at('2026-07-12 10:00:59.999999')));
        self::assertFalse($lease->isActiveAt($expires));
        $renewed = $lease->renew($authority, $issued, $this->at('2026-07-12 10:02:00.000000'));
        self::assertSame('2026-07-12 10:02:00.000000', $renewed->expiresAt->format('Y-m-d H:i:s.u'));

        foreach ([
            fn () => new ClaimLease($authority, $expires, $issued),
            fn () => new ClaimLease(
                $authority,
                new DateTimeImmutable('2026-07-12 12:00:00+02:00'),
                $expires,
            ),
            fn () => $lease->isActiveAt(new DateTimeImmutable('2026-07-12 12:00:00+02:00')),
            fn () => $lease->assertCurrent($this->authority(1, 'b'), $issued),
            fn () => $lease->assertCurrent($authority, $expires),
            fn () => $lease->renew($authority, $issued, $expires),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('An invalid lease operation was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSubmissionRecoveryUpidAndTerminalEnumsAreClosed(): void
    {
        $upid = new TaskUpid('UPID:node:00000001:00000002:00000003:vzdump:100:root@pam:');
        self::assertTrue($upid->equals(new TaskUpid($upid->value)));
        self::assertFalse($upid->equals(new TaskUpid('UPID:node:1:2:3:vzdump:101:root@pam:')));
        self::assertSame(SubmissionOutcomeKind::Accepted, SubmissionOutcome::accepted($upid)->kind);
        self::assertSame($upid, SubmissionOutcome::accepted($upid)->upid);
        self::assertSame(SubmissionOutcomeKind::Rejected, SubmissionOutcome::rejected()->kind);
        self::assertSame(SubmissionOutcomeKind::Ambiguous, SubmissionOutcome::ambiguous()->kind);
        self::assertSame(RecoveryOutcomeKind::Matched, RecoveryOutcome::matched($upid)->kind);
        self::assertSame(RecoveryOutcomeKind::ProvenNotStarted, RecoveryOutcome::provenNotStarted()->kind);
        self::assertSame(RecoveryOutcomeKind::Inconclusive, RecoveryOutcome::inconclusive()->kind);
        self::assertSame(RecoveryOutcomeKind::MultipleMatches, RecoveryOutcome::multipleMatches()->kind);
        self::assertFalse(RecoveryOutcome::matched($upid)->permitsNewAttempt());
        self::assertFalse(RecoveryOutcome::inconclusive()->permitsNewAttempt());
        self::assertTrue(RecoveryOutcome::provenNotStarted()->permitsNewAttempt());
        self::assertTrue(RecoveryOutcome::multipleMatches()->permitsNewAttempt());
        foreach (BackupRequestState::cases() as $state) {
            self::assertSame(in_array($state, [
                BackupRequestState::Succeeded,
                BackupRequestState::Failed,
                BackupRequestState::Cancelled,
                BackupRequestState::Unknown,
            ], true), $state->isTerminal());
        }
        foreach (BackupRunState::cases() as $state) {
            self::assertSame(in_array($state, [
                BackupRunState::Succeeded,
                BackupRunState::Failed,
                BackupRunState::Cancelled,
                BackupRunState::Unknown,
            ], true), $state->isTerminal());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUpids(): iterable
    {
        yield 'empty' => [''];
        yield 'short prefix' => ['UPI'];
        yield 'wrong prefix' => ['TASK:node'];
        yield 'nul' => ["UPID:node\0x"];
        yield 'whitespace' => ['UPID:node x'];
        yield 'too long' => ['UPID:'.str_repeat('x', 4092)];
    }

    #[DataProvider('invalidUpids')]
    public function testUpidRejectsEveryUnsafeBoundary(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaskUpid($value);
    }

    private function authority(int $fence, string $byte): ClaimAuthority
    {
        return new ClaimAuthority(new ClaimToken(str_repeat($byte, 16)), new ClaimFence($fence));
    }

    private function at(string $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'))
            ?: throw new InvalidArgumentException('Invalid test time.');
    }
}
