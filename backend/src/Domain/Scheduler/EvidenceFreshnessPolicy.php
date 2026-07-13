<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class EvidenceFreshnessPolicy
{
    public const int DEFAULT_SECONDS = 300;
    public const int MAXIMUM_SECONDS = 86400;

    public function __construct(public int $maximumAgeSeconds = self::DEFAULT_SECONDS)
    {
        if ($this->maximumAgeSeconds < 1 || $this->maximumAgeSeconds > self::MAXIMUM_SECONDS) {
            throw new InvalidArgumentException('The evidence freshness window must be between 1 and 86400 seconds.');
        }
    }

    public function evaluate(
        GateCode $code,
        GateScope $scope,
        GateSubjectId $subjectId,
        DateTimeImmutable $now,
        ?DateTimeImmutable $observedAt,
    ): GateResult {
        if (!\in_array($code, [
            GateCode::InventoryFresh,
            GateCode::PlacementFresh,
            GateCode::CapacityFresh,
            GateCode::ExecutorAuthorizationFresh,
        ], true)) {
            throw new InvalidArgumentException('The gate is not an evidence-freshness gate.');
        }

        if (null === $observedAt) {
            return new GateResult($code, false, $scope, $subjectId, null, GateDetailCode::Missing);
        }

        $utc = new DateTimeZone('UTC');
        $now = $now->setTimezone($utc);
        $observedAt = $observedAt->setTimezone($utc);
        $expiresAt = $observedAt->add(new DateInterval('PT'.$this->maximumAgeSeconds.'S'));
        $fresh = $observedAt <= $now && $now <= $expiresAt;

        return new GateResult(
            $code,
            $fresh,
            $scope,
            $subjectId,
            $observedAt,
            $fresh ? GateDetailCode::Passed : GateDetailCode::Stale,
        );
    }
}
