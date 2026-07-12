<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scheduler;

use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\Priority;
use App\Domain\Scheduler\PriorityResolver;
use App\Domain\Scheduler\ReasonPriority;
use App\Domain\Scheduler\RequestOrigin;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriorityResolverTest extends TestCase
{
    public function testTheFourAndOnlyFourClassesRemainStable(): void
    {
        self::assertSame([
            'manual' => 400,
            'never_backed_up' => 300,
            'max_age' => 200,
            'bytes_written' => 100,
        ], array_combine(
            array_map(static fn (BackupReason $reason): string => $reason->value, BackupReason::cases()),
            array_map(static fn (Priority $priority): int => $priority->value, Priority::cases()),
        ));
        self::assertSame(['automatic', 'manual', 'retry'], array_column(RequestOrigin::cases(), 'value'));
    }

    public function testManualRequestUsesTheManualClass(): void
    {
        $resolved = (new PriorityResolver())->resolve(RequestOrigin::Manual, BackupReason::Manual, null);

        self::assertSame(BackupReason::Manual, $resolved->reason);
        self::assertSame(Priority::Manual, $resolved->priority);
    }

    /** @return iterable<string, array{BackupReason, Priority}> */
    public static function automaticClasses(): iterable
    {
        yield 'never backed up' => [BackupReason::NeverBackedUp, Priority::NeverBackedUp];
        yield 'maximum age' => [BackupReason::MaxAge, Priority::MaxAge];
        yield 'bytes written' => [BackupReason::BytesWritten, Priority::BytesWritten];
    }

    #[DataProvider('automaticClasses')]
    public function testAutomaticReasonsResolveToTheirExactClass(BackupReason $reason, Priority $priority): void
    {
        $resolved = (new PriorityResolver())->resolve(RequestOrigin::Automatic, $reason, null);

        self::assertSame($reason, $resolved->reason);
        self::assertSame($priority, $resolved->priority);
        self::assertSame($priority, ReasonPriority::expected($reason));
    }

    #[DataProvider('automaticClasses')]
    public function testRetryReturnsTheUnchangedOriginalReasonAndPriority(
        BackupReason $reason,
        Priority $priority,
    ): void {
        $original = new ReasonPriority($reason, $priority);
        $resolved = (new PriorityResolver())->resolve(RequestOrigin::Retry, null, $original);

        self::assertSame($original, $resolved);
        self::assertSame($reason, $resolved->reason);
        self::assertSame($priority, $resolved->priority);
    }

    /** @return iterable<string, array{RequestOrigin, BackupReason|null, ReasonPriority|null}> */
    public static function invalidResolutionInputs(): iterable
    {
        $automatic = new ReasonPriority(BackupReason::MaxAge, Priority::MaxAge);

        yield 'manual missing reason' => [RequestOrigin::Manual, null, null];
        yield 'manual automatic reason' => [RequestOrigin::Manual, BackupReason::MaxAge, null];
        yield 'manual with original' => [RequestOrigin::Manual, BackupReason::Manual, $automatic];
        yield 'automatic missing reason' => [RequestOrigin::Automatic, null, null];
        yield 'automatic manual reason' => [RequestOrigin::Automatic, BackupReason::Manual, null];
        yield 'automatic with original' => [RequestOrigin::Automatic, BackupReason::MaxAge, $automatic];
        yield 'retry with new reason' => [RequestOrigin::Retry, BackupReason::MaxAge, $automatic];
        yield 'retry missing original' => [RequestOrigin::Retry, null, null];
    }

    #[DataProvider('invalidResolutionInputs')]
    public function testInvalidOriginCombinationsFailClosed(
        RequestOrigin $origin,
        ?BackupReason $reason,
        ?ReasonPriority $original,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        (new PriorityResolver())->resolve($origin, $reason, $original);
    }

    public function testAReasonCannotBePairedWithAnotherPriorityClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReasonPriority(BackupReason::MaxAge, Priority::NeverBackedUp);
    }
}
