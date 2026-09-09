<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class EvidenceFreshnessEvaluator
{
    public const int MAXIMUM_FRESHNESS_SECONDS = 86_400;

    public function __construct(public int $freshnessSeconds)
    {
        if ($freshnessSeconds < 1) {
            throw new InvalidArgumentException('The evidence freshness window must be between 1 and 86400 seconds.');
        }
        if ($freshnessSeconds > self::MAXIMUM_FRESHNESS_SECONDS) {
            throw new InvalidArgumentException('The evidence freshness window must be between 1 and 86400 seconds.');
        }
    }

    public function assess(DateTimeImmutable $now, ?DateTimeImmutable $observedAt): EvidenceFreshness
    {
        self::assertUtc($now);
        if (null === $observedAt) {
            return EvidenceFreshness::Missing;
        }
        self::assertUtc($observedAt);
        if ($observedAt > $now) {
            return EvidenceFreshness::Future;
        }

        return $now <= $observedAt->modify('+'.$this->freshnessSeconds.' seconds')
            ? EvidenceFreshness::Fresh
            : EvidenceFreshness::Stale;
    }

    private static function assertUtc(DateTimeImmutable $value): void
    {
        if (0 !== $value->getOffset()) {
            throw new InvalidArgumentException('Evidence freshness timestamps must use UTC.');
        }
    }
}
