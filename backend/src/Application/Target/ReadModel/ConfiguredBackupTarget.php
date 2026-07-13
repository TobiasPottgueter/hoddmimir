<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ConfiguredBackupTarget
{
    /** @var list<ConfiguredBackupTargetAllowedNode> */
    public array $allowedNodes;

    /** @var list<ConfiguredBackupTargetBlockerCode> */
    public array $blockers;

    /**
     * @param list<ConfiguredBackupTargetAllowedNode> $allowedNodes
     * @param list<ConfiguredBackupTargetBlockerCode> $blockers
     */
    public function __construct(
        public string $id,
        public int $revision,
        public bool $enabled,
        public string $displayName,
        public string $connectionId,
        public string $connectionName,
        public string $clusterId,
        public string $clusterName,
        public string $storageId,
        public string $storageName,
        public string $storageType,
        public ?UInt64Decimal $minimumFreeBytes,
        public ?int $fixedParallelLimit,
        public ?string $pbsConnectionId,
        public ?string $pbsDatastoreId,
        public ?string $pbsNamespaceId,
        public ?string $disabledAt,
        array $allowedNodes,
        array $blockers,
    ) {
        foreach ([$id, $connectionId, $clusterId, $storageId, $pbsConnectionId, $pbsDatastoreId, $pbsNamespaceId] as $identifier) {
            if (null !== $identifier) {
                new ReadModelIdentifier($identifier);
            }
        }
        foreach ([$displayName, $connectionName, $clusterName, $storageName, $storageType] as $text) {
            if ('' === $text || \strlen($text) > 255 || \str_contains($text, "\0")) {
                throw new InvalidArgumentException('Configured backup-target identity is invalid.');
            }
        }
        if ($revision < 1 || (null !== $fixedParallelLimit && $fixedParallelLimit < 1)) {
            throw new InvalidArgumentException('Configured backup-target limits are invalid.');
        }
        if ($enabled && null !== $disabledAt) {
            throw new InvalidArgumentException('An enabled backup target cannot have a disabled timestamp.');
        }
        if (null !== $disabledAt) {
            self::assertUtc($disabledAt);
        }

        $nodes = [];
        foreach ($allowedNodes as $node) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$node instanceof ConfiguredBackupTargetAllowedNode || isset($nodes[$node->id])) {
                throw new InvalidArgumentException('Configured backup-target nodes must be unique.');
            }
            $nodes[$node->id] = $node;
        }
        \usort($allowedNodes, static fn (ConfiguredBackupTargetAllowedNode $left, ConfiguredBackupTargetAllowedNode $right): int =>
            [$left->name, $left->id] <=> [$right->name, $right->id]);
        $this->allowedNodes = $allowedNodes;

        $seen = [];
        $normalized = [];
        foreach ($blockers as $blocker) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$blocker instanceof ConfiguredBackupTargetBlockerCode) {
                throw new InvalidArgumentException('Configured backup-target blockers are invalid.');
            }
            if (!isset($seen[$blocker->value])) {
                $seen[$blocker->value] = true;
                $normalized[] = $blocker;
            }
        }
        $this->blockers = $normalized;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'revision' => $this->revision,
            'enabled' => $this->enabled,
            'displayName' => $this->displayName,
            'connectionId' => $this->connectionId,
            'connectionName' => $this->connectionName,
            'clusterId' => $this->clusterId,
            'clusterName' => $this->clusterName,
            'storageId' => $this->storageId,
            'storageName' => $this->storageName,
            'storageType' => $this->storageType,
            'minimumFreeBytes' => $this->minimumFreeBytes?->value,
            'fixedParallelLimit' => $this->fixedParallelLimit,
            'pbsConnectionId' => $this->pbsConnectionId,
            'pbsDatastoreId' => $this->pbsDatastoreId,
            'pbsNamespaceId' => $this->pbsNamespaceId,
            'disabledAt' => $this->disabledAt,
            'allowedNodes' => \array_map(
                static fn (ConfiguredBackupTargetAllowedNode $node): array => $node->toArray(),
                $this->allowedNodes,
            ),
            'canEnable' => !$this->enabled && [] === $this->blockers,
            'blockers' => \array_column($this->blockers, 'value'),
        ];
    }

    private static function assertUtc(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException('Configured backup-target timestamps must be canonical UTC.');
        }
    }
}
