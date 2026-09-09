<?php

declare(strict_types=1);

namespace App\Domain\Target;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ActivationEvidenceObservation
{
    public ?DateTimeImmutable $observedAt;
    public ?DateTimeImmutable $newestObservedAt;

    public function __construct(
        public ?bool $accepted,
        ?DateTimeImmutable $observedAt,
        ?DateTimeImmutable $newestObservedAt = null,
    )
    {
        $this->observedAt = $observedAt?->setTimezone(new DateTimeZone('UTC'));
        $this->newestObservedAt = ($newestObservedAt ?? $observedAt)?->setTimezone(new DateTimeZone('UTC'));
        if (null !== $this->observedAt && null !== $this->newestObservedAt
            && $this->newestObservedAt < $this->observedAt) {
            throw new InvalidArgumentException('The evidence observation range is invalid.');
        }
    }

    public function freshness(DateTimeImmutable $now, int $maximumAgeSeconds = 300): EvidenceObservationFreshness
    {
        if ($maximumAgeSeconds < 1 || $maximumAgeSeconds > 86400) {
            throw new InvalidArgumentException('The evidence freshness window is invalid.');
        }
        if (null === $this->accepted || null === $this->observedAt || null === $this->newestObservedAt) {
            return EvidenceObservationFreshness::Missing;
        }
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        if ($this->newestObservedAt > $now) {
            return EvidenceObservationFreshness::Future;
        }
        $expiresAt = $this->observedAt->add(new DateInterval('PT'.$maximumAgeSeconds.'S'));
        return $now <= $expiresAt ? EvidenceObservationFreshness::Fresh : EvidenceObservationFreshness::Stale;
    }
}
