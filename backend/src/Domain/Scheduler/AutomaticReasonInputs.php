<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AutomaticReasonInputs
{
    private function __construct(
        public DateTimeImmutable $now,
        public ?DateTimeImmutable $lastSuccessAt,
        public ?DateTimeImmutable $maximumAgeBoundary,
        public ?ByteReasonEvidence $byteReasonEvidence,
    ) {
    }

    public static function withoutSuccessfulBackup(DateTimeImmutable $now): self
    {
        return new self(self::utc($now), null, null, null);
    }

    public static function withSuccessfulBackup(
        DateTimeImmutable $now,
        DateTimeImmutable $lastSuccessAt,
        DateTimeImmutable $maximumAgeBoundary,
        ?ByteReasonEvidence $byteReasonEvidence,
    ): self {
        $lastSuccessAt = self::utc($lastSuccessAt);
        $maximumAgeBoundary = self::utc($maximumAgeBoundary);
        if ($maximumAgeBoundary < $lastSuccessAt) {
            throw new InvalidArgumentException('The maximum-age boundary must not precede the last success.');
        }

        return new self(
            self::utc($now),
            $lastSuccessAt,
            $maximumAgeBoundary,
            $byteReasonEvidence,
        );
    }

    private static function utc(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
