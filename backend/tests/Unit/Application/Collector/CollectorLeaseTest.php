<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Collector;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorWorkerId;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CollectorLeaseTest extends TestCase
{
    public function testItNormalizesExpiryAndMatchesEveryOwnershipPart(): void
    {
        $owner = new CollectorWorkerId(str_repeat('a', 16));
        $token = new CollectorCycleToken(str_repeat('b', 16));
        $lease = new CollectorLease($owner, $token, 7, new DateTimeImmutable('2026-07-10T14:02:00+02:00'));

        self::assertSame('2026-07-10T12:02:00.000000+00:00', $lease->expiresAt->format('Y-m-d\TH:i:s.uP'));
        self::assertTrue($lease->isOwnedBy($owner, $token, 7));
        self::assertFalse($lease->isOwnedBy(new CollectorWorkerId(str_repeat('c', 16)), $token, 7));
        self::assertFalse($lease->isOwnedBy($owner, new CollectorCycleToken(str_repeat('d', 16)), 7));
        self::assertFalse($lease->isOwnedBy($owner, $token, 8));
    }

    public function testExpiryUsesAnInclusiveBoundary(): void
    {
        $lease = new CollectorLease(
            new CollectorWorkerId(str_repeat('a', 16)),
            new CollectorCycleToken(str_repeat('b', 16)),
            1,
            new DateTimeImmutable('2026-07-10T12:02:00+00:00'),
        );

        self::assertFalse($lease->isExpiredAt(new DateTimeImmutable('2026-07-10T12:01:59.999999+00:00')));
        self::assertTrue($lease->isExpiredAt(new DateTimeImmutable('2026-07-10T12:02:00+00:00')));
    }

    public function testItRejectsANonPositiveFencingToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fencing token');

        new CollectorLease(
            new CollectorWorkerId(str_repeat('a', 16)),
            new CollectorCycleToken(str_repeat('b', 16)),
            0,
            new DateTimeImmutable('2026-07-10T12:02:00+00:00'),
        );
    }
}
