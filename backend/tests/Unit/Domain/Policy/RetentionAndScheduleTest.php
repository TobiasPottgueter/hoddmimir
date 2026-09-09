<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Policy;

use App\Domain\Policy\BackupMode;
use App\Domain\Policy\Compression;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetentionAndScheduleTest extends TestCase
{
    public function testPolicyEnumsMirrorOnlyTheSupportedVzdumpValues(): void
    {
        self::assertSame(['snapshot', 'suspend', 'stop'], array_column(BackupMode::cases(), 'value'));
        self::assertSame(['0', 'gzip', 'lzo', 'zstd'], array_column(Compression::cases(), 'value'));
        self::assertSame(['collector_cycle'], array_column(Schedule::cases(), 'value'));
    }

    public function testCollectorCycleScheduleUsesTheAuthoritativeCycleStartInUtc(): void
    {
        $cycleStart = new DateTimeImmutable('2026-07-12T22:30:00.123456+02:00');

        self::assertSame(
            '2026-07-12T20:30:00.123456+00:00',
            Schedule::CollectorCycle->scheduledAt($cycleStart)->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function testLegacyRetentionIsCanonicalAndLimitedToPveSevenAndEight(): void
    {
        $retention = RetentionPolicy::legacyMaxFiles(7);

        self::assertSame(['maxfiles' => 7], $retention->signature());
        self::assertTrue($retention->supportsPveMajor(7));
        self::assertTrue($retention->supportsPveMajor(8));
        self::assertFalse($retention->supportsPveMajor(6));
        self::assertFalse($retention->supportsPveMajor(9));
        self::assertFalse($retention->supportsPveMajor(10));
    }

    public function testPruneRetentionIsCanonicalAndSupportedByPveNine(): void
    {
        $retention = RetentionPolicy::prune(false, 3, null, 7, null, 2, 1);

        self::assertSame([
            'prune-backups' => [
                'keep-all' => false,
                'keep-last' => 3,
                'keep-daily' => 7,
                'keep-monthly' => 2,
                'keep-yearly' => 1,
            ],
        ], $retention->signature());
        self::assertTrue($retention->supportsPveMajor(9));
        self::assertSame(
            ['prune-backups' => ['keep-all' => true]],
            RetentionPolicy::prune(true, null, null, null, null, null, null)->signature(),
        );
    }

    #[DataProvider('invalidCountProvider')]
    public function testLegacyRetentionRejectsCountsOutsideTheWriteContract(int $count): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetentionPolicy::legacyMaxFiles($count);
    }

    #[DataProvider('invalidCountProvider')]
    public function testPruneRetentionRejectsCountsOutsideTheWriteContract(int $count): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetentionPolicy::prune(null, $count, null, null, null, null, null);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidCountProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'above maximum' => [1_000_001];
    }

    public function testPruneRetentionMustBeExplicit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetentionPolicy::prune(null, null, null, null, null, null, null);
    }

    public function testKeepAllCannotBeCombinedWithCountedRules(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetentionPolicy::prune(true, 1, null, null, null, null, null);
    }

    public function testKeepAllFalseCannotStandAloneAsAnImplicitDeleteAllRule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetentionPolicy::prune(false, null, null, null, null, null, null);
    }
}
