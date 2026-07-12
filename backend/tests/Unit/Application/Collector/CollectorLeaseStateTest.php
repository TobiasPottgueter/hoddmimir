<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Collector;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorLeaseState;
use App\Application\Collector\CollectorLeaseUnavailable;
use App\Application\Collector\CollectorWorkerId;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectorLeaseStateTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    public function testAClaimCreatesAnUtcLeaseAndIncrementsTheFencingToken(): void
    {
        $state = (new CollectorLeaseState(4))->claim(
            $this->owner('a'),
            $this->token('b'),
            new DateTimeImmutable('2026-07-10T14:00:00+02:00'),
            30,
        );

        self::assertSame(5, $state->lastFencingToken);
        self::assertNotNull($state->activeLease);
        self::assertSame(5, $state->activeLease->fencingToken);
        self::assertSame('2026-07-10T12:00:30.000000+00:00', $state->activeLease->expiresAt->format('Y-m-d\TH:i:s.uP'));
    }

    public function testMinimumClaimAndRenewTtlsAreAcceptedExactly(): void
    {
        $owner = $this->owner('a');
        $token = $this->token('b');
        $state = (new CollectorLeaseState())->claim($owner, $token, new DateTimeImmutable(self::NOW), 1);
        self::assertSame('2026-07-10T12:00:01.000000+00:00', $state->activeLease?->expiresAt->format('Y-m-d\TH:i:s.uP'));

        $state = $state->renew($owner, $token, 1, new DateTimeImmutable('2026-07-10T12:00:00.500000+00:00'), 1);
        self::assertSame('2026-07-10T12:00:01.500000+00:00', $state->activeLease?->expiresAt->format('Y-m-d\TH:i:s.uP'));
    }

    public function testAnUnexpiredLeaseBlocksAnotherClaim(): void
    {
        $state = (new CollectorLeaseState())->claim($this->owner('a'), $this->token('a'), new DateTimeImmutable(self::NOW), 30);

        $this->expectException(CollectorLeaseUnavailable::class);
        $state->claim($this->owner('b'), $this->token('b'), new DateTimeImmutable('2026-07-10T12:00:29.999999+00:00'), 30);
    }

    public function testAClaimAtTheExpiryBoundaryGetsANewFencingToken(): void
    {
        $state = (new CollectorLeaseState())->claim($this->owner('a'), $this->token('a'), new DateTimeImmutable(self::NOW), 30);
        $state = $state->claim($this->owner('b'), $this->token('b'), new DateTimeImmutable('2026-07-10T12:00:30+00:00'), 20);

        self::assertSame(2, $state->lastFencingToken);
        self::assertTrue($state->activeLease?->isOwnedBy($this->owner('b'), $this->token('b'), 2));
    }

    public function testTheOwnerCanRenewAndReleaseAValidLease(): void
    {
        $owner = $this->owner('a');
        $token = $this->token('a');
        $state = (new CollectorLeaseState())->claim($owner, $token, new DateTimeImmutable(self::NOW), 30);
        $state = $state->renew($owner, $token, 1, new DateTimeImmutable('2026-07-10T12:00:10+00:00'), 50);

        self::assertSame('2026-07-10T12:01:00.000000+00:00', $state->activeLease?->expiresAt->format('Y-m-d\TH:i:s.uP'));
        $state = $state->release($owner, $token, 1, new DateTimeImmutable('2026-07-10T12:00:59.999999+00:00'));
        self::assertSame(1, $state->lastFencingToken);
        self::assertNull($state->activeLease);
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function invalidOwnership(): iterable
    {
        yield 'wrong owner' => ['b', 'a', 1, '2026-07-10T12:00:01+00:00'];
        yield 'wrong token' => ['a', 'b', 1, '2026-07-10T12:00:01+00:00'];
        yield 'stale fencing token' => ['a', 'a', 2, '2026-07-10T12:00:01+00:00'];
        yield 'expired lease' => ['a', 'a', 1, '2026-07-10T12:00:30+00:00'];
    }

    #[DataProvider('invalidOwnership')]
    public function testInvalidOwnershipCannotRenew(string $owner, string $token, int $fence, string $now): void
    {
        $state = (new CollectorLeaseState())->claim($this->owner('a'), $this->token('a'), new DateTimeImmutable(self::NOW), 30);
        $this->expectException(CollectorLeaseOwnershipLost::class);
        $state->renew($this->owner($owner), $this->token($token), $fence, new DateTimeImmutable($now), 60);
    }

    public function testMissingOwnershipCannotRelease(): void
    {
        $this->expectException(CollectorLeaseOwnershipLost::class);
        (new CollectorLeaseState())->release($this->owner('a'), $this->token('a'), 1, new DateTimeImmutable(self::NOW));
    }

    public function testRenewalMustExtendTheExistingExpiry(): void
    {
        $owner = $this->owner('a');
        $token = $this->token('a');
        $state = (new CollectorLeaseState())->claim($owner, $token, new DateTimeImmutable(self::NOW), 30);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('extend its expiry');
        $state->renew($owner, $token, 1, new DateTimeImmutable('2026-07-10T12:00:01+00:00'), 29);
    }

    public function testItRejectsInvalidStateAndTtl(): void
    {
        try {
            new CollectorLeaseState(-1);
            self::fail('A negative fencing token must fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must not be negative', $exception->getMessage());
        }

        foreach ([[0, 1], [2, 1]] as [$lastToken, $activeToken]) {
            try {
                new CollectorLeaseState(
                    $lastToken,
                    new CollectorLease($this->owner('a'), $this->token('a'), $activeToken, new DateTimeImmutable(self::NOW)),
                );
                self::fail('An active lease with a stale or future token must fail.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must use the last', $exception->getMessage());
            }
        }

        try {
            (new CollectorLeaseState())->claim($this->owner('a'), $this->token('a'), new DateTimeImmutable(self::NOW), 0);
            self::fail('A zero claim TTL must fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('TTL', $exception->getMessage());
        }

        $state = (new CollectorLeaseState())->claim($this->owner('a'), $this->token('a'), new DateTimeImmutable(self::NOW), 30);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL');
        $state->renew($this->owner('a'), $this->token('a'), 1, new DateTimeImmutable('2026-07-10T12:00:01+00:00'), 0);
    }

    private function owner(string $byte): CollectorWorkerId
    {
        return new CollectorWorkerId(str_repeat($byte, 16));
    }

    private function token(string $byte): CollectorCycleToken
    {
        return new CollectorCycleToken(str_repeat($byte, 16));
    }
}
