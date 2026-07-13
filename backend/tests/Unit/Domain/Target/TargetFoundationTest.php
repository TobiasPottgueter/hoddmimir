<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Target;

use App\Domain\Shared\UInt64Decimal;
use App\Domain\Target\BackupTargetId;
use App\Domain\Target\MinimumFreeBytes;
use App\Domain\Target\TargetDraftAssessment;
use App\Domain\Target\TargetDraftBlockerCode;
use App\Domain\Target\TargetRevision;
use App\Domain\Target\TargetStatus;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TargetFoundationTest extends TestCase
{
    public function testTargetIdentifiersAreBinaryTypedAndComparable(): void
    {
        $bytes = random_bytes(16);
        $id = new BackupTargetId($bytes);

        self::assertSame($bytes, $id->binary());
        self::assertSame(bin2hex($bytes), $id->toHex());
        self::assertTrue($id->equals(new BackupTargetId($bytes)));
        self::assertFalse($id->equals(new BackupTargetId(random_bytes(16))));
    }

    #[DataProvider('invalidIdLengths')]
    public function testTargetIdentifiersRejectEveryInvalidBoundary(string $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupTargetId($bytes);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdLengths(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 15)];
        yield 'long' => [str_repeat('a', 17)];
    }

    public function testTargetRevisionIsPositiveAndMonotone(): void
    {
        $revision = new TargetRevision(1);

        self::assertSame(1, $revision->value);
        self::assertSame(2, $revision->next()->value);
    }

    #[DataProvider('invalidRevisions')]
    public function testTargetRevisionRejectsNonPositiveValues(int $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TargetRevision($value);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidRevisions(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testTargetRevisionFailsClosedAtPlatformIntegerLimit(): void
    {
        $this->expectException(OverflowException::class);
        (new TargetRevision(PHP_INT_MAX))->next();
    }

    #[DataProvider('minimumFreeByteValues')]
    public function testMinimumFreeBytesRetainsCanonicalUInt64(string $value): void
    {
        $minimum = new MinimumFreeBytes($value);

        self::assertSame($value, $minimum->decimal());
        self::assertSame($value, $minimum->bytes->value);
    }

    /** @return iterable<string, array{string}> */
    public static function minimumFreeByteValues(): iterable
    {
        yield 'zero is explicit and not a default' => ['0'];
        yield 'one' => ['1'];
        yield 'maximum uint64' => [UInt64Decimal::MAXIMUM];
    }

    #[DataProvider('invalidMinimumFreeByteValues')]
    public function testMinimumFreeBytesRejectsNonCanonicalOrOutOfRangeValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MinimumFreeBytes($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMinimumFreeByteValues(): iterable
    {
        yield 'empty' => [''];
        yield 'leading zero' => ['01'];
        yield 'negative' => ['-1'];
        yield 'overflow' => ['18446744073709551616'];
    }

    public function testDraftWithoutMinimumFreeBytesExposesEveryUnconfiguredBoundary(): void
    {
        $assessment = TargetDraftAssessment::assessDisabled(null);

        self::assertFalse($assessment->canEnable());
        self::assertSame([
            TargetDraftBlockerCode::TargetDisabled,
            TargetDraftBlockerCode::MinimumFreeBytesUnconfigured,
            TargetDraftBlockerCode::ConcurrencyPolicyUnconfigured,
        ], $assessment->blockers);
    }

    public function testExplicitMinimumFreeBytesDoesNotInventConcurrencyDefaults(): void
    {
        $assessment = TargetDraftAssessment::assessDisabled(new MinimumFreeBytes('0'));

        self::assertFalse($assessment->canEnable());
        self::assertSame([
            TargetDraftBlockerCode::TargetDisabled,
            TargetDraftBlockerCode::ConcurrencyPolicyUnconfigured,
        ], $assessment->blockers);
        self::assertSame(['disabled', 'enabled'], array_column(TargetStatus::cases(), 'value'));
    }
}
