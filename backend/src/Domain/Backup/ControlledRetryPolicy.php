<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ControlledRetryPolicy
{
    private const array DELAYS_SECONDS = [60, 300, 900, 1_800, 3_600];

    public function delayAfterAttempt(
        int $completedAttempt,
        SubmissionProvenance $provenance,
    ): int {
        if ($completedAttempt < 1) {
            throw new InvalidArgumentException('A completed backup attempt must be positive.');
        }
        if (!\in_array($provenance, [
            SubmissionProvenance::Accepted,
            SubmissionProvenance::DefinitiveRejection,
        ], true)) {
            throw new InvalidArgumentException('Only a definitive backup outcome may be retried.');
        }

        $index = \min($completedAttempt - 1, \count(self::DELAYS_SECONDS) - 1);

        return self::DELAYS_SECONDS[$index];
    }

    public function nextAvailableAt(
        DateTimeImmutable $failedAt,
        int $completedAttempt,
        SubmissionProvenance $provenance,
    ): DateTimeImmutable {
        if (0 !== $failedAt->getOffset()) {
            throw new InvalidArgumentException('A retry timestamp must use UTC.');
        }

        return $failedAt->add(new DateInterval(
            'PT'.$this->delayAfterAttempt($completedAttempt, $provenance).'S',
        ));
    }
}
