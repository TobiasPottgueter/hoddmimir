<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

use DateTimeImmutable;
use App\Domain\Shared\UInt64Decimal;

final readonly class ClaimedBackupRequest
{
    public function __construct(
        public string $id,
        public string $claimToken,
        public int $claimFence,
        public DateTimeImmutable $expiresAt,
        public string $nodeId,
        public string $targetId,
        public string $expectedSizeBytes,
        public string $state,
        public ?string $runId = null,
    ) {
        foreach ([$id, $claimToken, $nodeId, $targetId] as $value) {
            if (16 !== \strlen($value)) {
                throw new \InvalidArgumentException('Claimed identifiers must contain 16 bytes.');
            }
        }
        if ($claimFence < 1 || 0 !== $expiresAt->getOffset()
            || !\in_array($state, ['leased', 'starting', 'running', 'reconcile_required'], true)) {
            throw new \InvalidArgumentException('Claimed queue state is invalid.');
        }
        if (('leased' === $state) !== (null === $runId)) {
            throw new \InvalidArgumentException('A claimed queue run identifier is inconsistent with its state.');
        }
        if (null !== $runId && 16 !== \strlen($runId)) {
            throw new \InvalidArgumentException('A claimed queue run identifier must contain 16 bytes.');
        }
        new UInt64Decimal($expectedSizeBytes);
    }
}
