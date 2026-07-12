<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Collector;

use App\Application\Collector\CollectorActiveCycle;
use App\Application\Collector\CollectorClaimDecision;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorShutdownRequested;
use App\Application\Collector\CollectorStartDecision;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorWorkerRunCode;
use App\Application\Collector\CollectorWorkerRunResult;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectorIdentifiersAndDecisionsTest extends TestCase
{
    public function testTypedIdentifiersRoundTripLowercaseHex(): void
    {
        $hex = '00112233445566778899aabbccddeeff';

        self::assertSame($hex, (new CollectorWorkerId(pack('H*', $hex)))->toHex());
        self::assertSame(pack('H*', $hex), (new CollectorCycleToken(pack('H*', $hex)))->binary());
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function invalidIdentifiers(): iterable
    {
        yield 'worker byte length' => [CollectorWorkerId::class, 'short'];
        yield 'cycle byte length' => [CollectorCycleToken::class, 'short'];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testTypedIdentifiersRejectInvalidByteLengths(string $class, string $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new $class($bytes);
    }

    public function testCycleTokensAreRedactedFromDebugAndCannotBeSerialized(): void
    {
        $token = new CollectorCycleToken(str_repeat('s', 16));
        self::assertSame(['token' => '[redacted]'], $token->__debugInfo());
        self::assertStringNotContainsString(str_repeat('s', 16), print_r($token, true));

        $this->expectException(\LogicException::class);
        serialize($token);
    }

    public function testClaimAndWaitDecisionsExposeTheirExactState(): void
    {
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $tick = $now->modify('-2 minutes');
        $lease = $this->lease($now->modify('+4 minutes'));
        $claimed = CollectorClaimDecision::claimed($lease, $tick, $now);
        $waiting = CollectorClaimDecision::waiting($now, $now->modify('+2 minutes'));

        self::assertTrue($claimed->isClaimed());
        self::assertSame($tick, $claimed->scheduledFor);
        self::assertSame($lease->expiresAt, $claimed->retryAt);
        self::assertFalse($waiting->isClaimed());
        self::assertNull($waiting->scheduledFor);
    }

    public function testActiveCycleAndStartDecisionValidateAndExposeState(): void
    {
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $cycle = new CollectorActiveCycle($this->lease($now->modify('+4 minutes')), $now, 12);
        $decision = new CollectorStartDecision($cycle, $now, $cycle->lease->expiresAt);

        self::assertTrue($decision->isStarted());
        self::assertFalse((new CollectorStartDecision(null, $now, $now))->isStarted());

        $this->expectException(InvalidArgumentException::class);
        new CollectorActiveCycle($cycle->lease, $now, -1);
    }

    public function testShutdownExceptionAndStoppedResultKeepTheirStableContracts(): void
    {
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $cycle = new CollectorActiveCycle($this->lease($now->modify('+4 minutes')), $now, 0);
        $shutdown = new CollectorShutdownRequested($cycle);
        self::assertSame($cycle, $shutdown->cycle);
        self::assertSame('Collector shutdown was requested at a safe checkpoint.', $shutdown->getMessage());
        self::assertSame(0, $shutdown->getCode());

        $stopped = new CollectorWorkerRunResult(CollectorWorkerRunCode::CollectorStopped);
        self::assertSame(0, $stopped->exitCode());
        self::assertSame([
            'component' => 'collector',
            'status' => 'ok',
            'code' => 'collector_stopped',
        ], $stopped->toArray());
    }

    private function lease(DateTimeImmutable $expiry): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId(str_repeat('a', 16)),
            new CollectorCycleToken(str_repeat('b', 16)),
            1,
            $expiry,
        );
    }
}
