<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Target\ReadModel;

use App\Application\Target\ReadModel\EvidenceFreshness;
use App\Application\Target\ReadModel\EvidenceFreshnessEvaluator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EvidenceFreshnessEvaluatorTest extends TestCase
{
    public function testInclusiveBoundaryMissingFutureAndFirstStaleMicrosecondAreClosed(): void
    {
        $evaluator = new EvidenceFreshnessEvaluator(300);
        $now = new DateTimeImmutable('2026-07-12T10:05:00.000000Z');

        self::assertSame(EvidenceFreshness::Missing, $evaluator->assess($now, null));
        self::assertSame(
            EvidenceFreshness::Fresh,
            $evaluator->assess($now, new DateTimeImmutable('2026-07-12T10:00:00.000000Z')),
        );
        self::assertSame(
            EvidenceFreshness::Stale,
            $evaluator->assess($now, new DateTimeImmutable('2026-07-12T09:59:59.999999Z')),
        );
        self::assertSame(
            EvidenceFreshness::Future,
            $evaluator->assess($now, new DateTimeImmutable('2026-07-12T10:05:00.000001Z')),
        );
    }

    public function testWindowAndUtcInputsFailClosed(): void
    {
        foreach ([
            static fn () => new EvidenceFreshnessEvaluator(0),
            static fn () => new EvidenceFreshnessEvaluator(86_401),
            static fn () => (new EvidenceFreshnessEvaluator(300))->assess(
                new DateTimeImmutable('2026-07-12T12:05:00+02:00'),
                null,
            ),
            static fn () => (new EvidenceFreshnessEvaluator(300))->assess(
                new DateTimeImmutable('2026-07-12T10:05:00Z'),
                new DateTimeImmutable('2026-07-12T12:00:00+02:00'),
            ),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid freshness input was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
