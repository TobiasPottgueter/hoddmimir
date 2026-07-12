<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

final readonly class ReasonSelector
{
    public function select(AutomaticReasonInputs $inputs): ?BackupReason
    {
        if (null === $inputs->lastSuccessAt) {
            return BackupReason::NeverBackedUp;
        }

        /** @var \DateTimeImmutable $maximumAgeBoundary */
        $maximumAgeBoundary = $inputs->maximumAgeBoundary;
        if ($inputs->now > $maximumAgeBoundary) {
            return BackupReason::MaxAge;
        }

        $byteEvidence = $inputs->byteReasonEvidence;
        if (null !== $byteEvidence
            && $inputs->now > $byteEvidence->cooldownBoundary
            && $byteEvidence->currentBytes - $byteEvidence->baselineBytes > $byteEvidence->bytesThreshold) {
            return BackupReason::BytesWritten;
        }

        return null;
    }
}
