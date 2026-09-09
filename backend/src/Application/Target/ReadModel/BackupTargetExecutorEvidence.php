<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use InvalidArgumentException;

final readonly class BackupTargetExecutorEvidence
{
    /** @param list<BackupTargetBlockerCode> $blockers */
    public function __construct(
        public BackupTargetExecutorStatus $status,
        public int $targetCount,
        public int $expectedNodeCount,
        public int $observedNodeCount,
        public ?bool $vmBackupAuthorized,
        public ?bool $datastoreAllocateAuthorized,
        public ?bool $authorized,
        public EvidenceFreshness $freshness,
        public ?string $observedAt,
        public array $blockers,
    ) {
        if ($targetCount < 0 || $expectedNodeCount < 0 || $observedNodeCount < 0 || $observedNodeCount > $expectedNodeCount) {
            throw new InvalidArgumentException('Executor evidence counts are invalid.');
        }
        if (BackupTargetExecutorStatus::RequiresTargetConfiguration === $status
            && (0 !== $targetCount || 0 !== $expectedNodeCount || 0 !== $observedNodeCount || null !== $authorized)) {
            throw new InvalidArgumentException('Unconfigured executor evidence is invalid.');
        }
        if (BackupTargetExecutorStatus::Authorized === $status && true !== $authorized) {
            throw new InvalidArgumentException('Authorized executor evidence is invalid.');
        }
        if (BackupTargetExecutorStatus::Unauthorized === $status && false !== $authorized) {
            throw new InvalidArgumentException('Unauthorized executor evidence is invalid.');
        }
        if ((EvidenceFreshness::Missing === $freshness) !== (null === $observedAt)) {
            throw new InvalidArgumentException('Executor evidence timestamp is invalid.');
        }
    }

    public function usable(): bool
    {
        return BackupTargetExecutorStatus::Authorized === $this->status
            && EvidenceFreshness::Fresh === $this->freshness
            && [] === $this->blockers;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'targetCount' => $this->targetCount,
            'expectedNodeCount' => $this->expectedNodeCount,
            'observedNodeCount' => $this->observedNodeCount,
            'vmBackupAuthorized' => $this->vmBackupAuthorized,
            'datastoreAllocateAuthorized' => $this->datastoreAllocateAuthorized,
            'authorized' => $this->authorized,
            'freshness' => $this->freshness->value,
            'observedAt' => $this->observedAt,
            'blockers' => array_column($this->blockers, 'value'),
        ];
    }
}
