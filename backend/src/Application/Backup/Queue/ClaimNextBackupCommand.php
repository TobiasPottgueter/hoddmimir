<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

use DateTimeImmutable;

final readonly class ClaimNextBackupCommand
{
    public function __construct(
        public string $workerId,
        public DateTimeImmutable $now,
        public int $leaseSeconds = 120,
        public bool $allowNewClaims = true,
    ) {
        if (16 !== strlen($workerId) || $leaseSeconds < 1 || $leaseSeconds > 3600 || 0 !== $now->getOffset()) {
            throw new \InvalidArgumentException('Claim command is invalid.');
        }
    }
}
