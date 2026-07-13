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

    public function __construct(public ?bool $accepted, ?DateTimeImmutable $observedAt)
    {
        $this->observedAt = $observedAt?->setTimezone(new DateTimeZone('UTC'));
    }

    public function freshness(DateTimeImmutable $now, int $maximumAgeSeconds = 300): EvidenceObservationFreshness
    {
        if ($maximumAgeSeconds < 1 || $maximumAgeSeconds > 86400) {
            throw new InvalidArgumentException('The evidence freshness window is invalid.');
        }
        if (null === $this->accepted || null === $this->observedAt) {
            return EvidenceObservationFreshness::Missing;
        }
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        if ($this->observedAt > $now) {
            return EvidenceObservationFreshness::Future;
        }
        $expiresAt = $this->observedAt->add(new DateInterval('PT'.$maximumAgeSeconds.'S'));
        return $now <= $expiresAt ? EvidenceObservationFreshness::Fresh : EvidenceObservationFreshness::Stale;
    }
}
