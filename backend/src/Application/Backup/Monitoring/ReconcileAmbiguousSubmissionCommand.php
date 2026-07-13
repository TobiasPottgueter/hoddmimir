<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReconcileAmbiguousSubmissionCommand
{
    public function __construct(
        public string $requestId,
        public string $runId,
        public string $claimToken,
        public int $claimFence,
        public DateTimeImmutable $now,
    ) {
        foreach ([$requestId, $runId, $claimToken] as $id) {
            if (16 !== strlen($id)) {
                throw new InvalidArgumentException('Reconciliation identifiers must contain 16 bytes.');
            }
        }
        if ($claimFence < 1 || 0 !== $now->getOffset()) {
            throw new InvalidArgumentException('Reconciliation claim authority is invalid.');
        }
    }
}
