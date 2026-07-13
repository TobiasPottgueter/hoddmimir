<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class BackupTargetCandidate
{
    /** @var list<BackupTargetNodeEvidence> */
    public array $nodes;
    /** @var list<BackupTargetBlockerCode> */
    public array $blockers;
    public BackupTargetExecutorEvidence $executor;

    /**
     * @param list<BackupTargetNodeEvidence> $nodes
     * @param list<BackupTargetBlockerCode>  $blockers
     */
    public function __construct(
        public string $id,
        public string $connectionId,
        public string $connectionName,
        public string $clusterId,
        public string $clusterName,
        public string $storageName,
        public string $storageType,
        public bool $shared,
        public string $inventoryState,
        public ?string $observedAt,
        array $nodes,
        public ?PbsBackupTargetEvidence $pbs,
        array $blockers,
        ?BackupTargetExecutorEvidence $executor = null,
    ) {
        new ReadModelIdentifier($id);
        new ReadModelIdentifier($connectionId);
        new ReadModelIdentifier($clusterId);
        if ('' === $connectionName || '' === $clusterName || '' === $storageName || '' === $storageType) {
            throw new InvalidArgumentException('Backup-target candidate identity is invalid.');
        }
        if ('active' !== $inventoryState && 'archived' !== $inventoryState) {
            throw new InvalidArgumentException('Backup-target candidate identity is invalid.');
        }
        if (null !== $observedAt) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $observedAt, new DateTimeZone('UTC'));
            if (false === $date || $date->format('Y-m-d\TH:i:s.u\Z') !== $observedAt) {
                throw new InvalidArgumentException('Backup-target candidate timestamps must be canonical UTC.');
            }
        }
        foreach ($nodes as $node) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the PHPDoc boundary at runtime)
            if (!$node instanceof BackupTargetNodeEvidence) {
                throw new InvalidArgumentException('Backup-target node evidence is invalid.');
            }
        }
        $this->nodes = $nodes;
        $this->executor = $executor ?? new BackupTargetExecutorEvidence(
            BackupTargetExecutorStatus::RequiresTargetConfiguration,
            0, 0, 0, null, null, null, EvidenceFreshness::Missing, null, [],
        );
        $seen = [];
        $normalized = [];
        foreach ($blockers as $blocker) {
            if (!isset($seen[$blocker->value])) {
                $seen[$blocker->value] = true;
                $normalized[] = $blocker;
            }
        }
        $this->blockers = $normalized;
    }

    public function canEnable(): bool
    {
        return 'active' === $this->inventoryState
            && [] !== array_filter($this->nodes, static fn (BackupTargetNodeEvidence $node): bool => $node->usable())
            && [] === $this->blockers
            && $this->executor->usable()
            && ('pbs' !== $this->storageType
                ? null === $this->pbs
                : null !== $this->pbs
                    && PbsEndpointMatchStatus::Matched === $this->pbs->endpointMatch
                    && [] === $this->pbs->blockers);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'connectionId' => $this->connectionId,
            'connectionName' => $this->connectionName,
            'clusterId' => $this->clusterId,
            'clusterName' => $this->clusterName,
            'storageName' => $this->storageName,
            'storageType' => $this->storageType,
            'shared' => $this->shared,
            'inventoryState' => $this->inventoryState,
            'observedAt' => $this->observedAt,
            'canEnable' => $this->canEnable(),
            'nodes' => array_map(static fn (BackupTargetNodeEvidence $node): array => $node->toArray(), $this->nodes),
            'executor' => $this->executor->toArray(),
            'pbs' => $this->pbs?->toArray(),
            'blockers' => array_column($this->blockers, 'value'),
        ];
    }
}
