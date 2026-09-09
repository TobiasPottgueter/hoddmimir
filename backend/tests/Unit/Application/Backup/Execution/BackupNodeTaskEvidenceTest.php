<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Execution;

use App\Application\Backup\Execution\BackupNodeTaskEvidence;
use App\Domain\Backup\RecoveryOutcome;
use App\Domain\Backup\TaskUpid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackupNodeTaskEvidenceTest extends TestCase
{
    public function testEvidenceRequiresAllNodesAndIsBoundedIncludingEquality(): void
    {
        $at = new DateTimeImmutable('2026-09-07T20:00:00Z');
        $evidence = new BackupNodeTaskEvidence(['old-node', 'new-node'], $at);
        self::assertTrue($evidence->covers(['old-node', 'new-node'], $at));
        self::assertTrue($evidence->covers(['new-node'], $at->modify('+30 seconds')));
        self::assertFalse($evidence->covers(['new-node'], $at->modify('+30 seconds +1 microsecond')));
        self::assertFalse($evidence->covers(['new-node'], $at->modify('-1 microsecond')));
        self::assertFalse($evidence->covers(['other-node'], $at));
        self::assertFalse($evidence->covers([], $at));
    }

    public function testEmptyScopeIsNotEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupNodeTaskEvidence([], new DateTimeImmutable('2026-09-07T20:00:00Z'));
    }

    public function testInvalidNodeIsNotEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupNodeTaskEvidence(['../other'], new DateTimeImmutable('2026-09-07T20:00:00Z'));
    }

    public function testEvidenceRequiresUtc(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupNodeTaskEvidence(['node-a'], new DateTimeImmutable('2026-09-07T20:00:00+02:00'));
    }

    public function testOnlyCompleteSearchWithoutUniqueMatchAuthorizesANewAttempt(): void
    {
        self::assertTrue(RecoveryOutcome::provenNotStarted()->permitsNewAttempt());
        self::assertTrue(RecoveryOutcome::multipleMatches()->permitsNewAttempt());
        self::assertFalse(RecoveryOutcome::inconclusive()->permitsNewAttempt());
        self::assertFalse(RecoveryOutcome::matched(new TaskUpid('UPID:node-a:00000001:00000002:67000000:vzdump:100:backup@pve:'))->permitsNewAttempt());
    }
}
