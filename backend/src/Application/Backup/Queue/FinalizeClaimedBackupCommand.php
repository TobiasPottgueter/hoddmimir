<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FinalizeClaimedBackupCommand
{
    public function __construct(
        public string $requestId,
        public string $claimToken,
        public int $claimFence,
        public string $terminalState,
        public DateTimeImmutable $now,
        public ?string $detailCode = null,
    ) {
        if (16 !== \strlen($requestId) || 16 !== \strlen($claimToken) || $claimFence < 1
            || !\in_array($terminalState, ['succeeded', 'failed', 'cancelled', 'unknown'], true)
            || 0 !== $now->getOffset()
            || (null !== $detailCode && 1 !== \preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $detailCode))) {
            throw new InvalidArgumentException('The terminal queue transition is invalid.');
        }
    }
}
