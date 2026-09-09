<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class BackupTargetNodeEvidence
{
    /** @var list<BackupTargetBlockerCode> */
    public array $blockers;

    /**
     * @param list<BackupTargetBlockerCode> $blockers
     */
    public function __construct(
        public string $nodeId,
        public string $nodeName,
        public bool $configuredForStorage,
        public ?bool $enabled,
        public ?bool $active,
        public BackupTargetCapacityStatus $capacityStatus,
        public ?UInt64Decimal $totalBytes,
        public ?UInt64Decimal $usedBytes,
        public ?UInt64Decimal $availableBytes,
        public ?string $observedAt,
        array $blockers,
    ) {
        self::assertUuid($nodeId);
        if ('' === $nodeName) {
            throw new InvalidArgumentException('A target-candidate node name must not be empty.');
        }
        $measured = BackupTargetCapacityStatus::Measured === $capacityStatus;
        if ($measured !== (null !== $totalBytes && null !== $usedBytes && null !== $availableBytes)) {
            throw new InvalidArgumentException('Measured target capacity requires exactly three byte values.');
        }
        if ($measured) {
            /** @var UInt64Decimal $totalBytes */
            /** @var UInt64Decimal $usedBytes */
            /** @var UInt64Decimal $availableBytes */
            if (!$usedBytes->lessThanOrEqual($totalBytes) || !$availableBytes->lessThanOrEqual($totalBytes)) {
                throw new InvalidArgumentException('Target capacity parts must not exceed total bytes.');
            }
        }
        if (null !== $observedAt) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $observedAt, new DateTimeZone('UTC'));
            if (false === $date || $date->format('Y-m-d\TH:i:s.u\Z') !== $observedAt) {
                throw new InvalidArgumentException('Target node evidence timestamps must be canonical UTC.');
            }
        }
        $this->blockers = self::uniqueBlockers($blockers);
    }

    public function usable(): bool
    {
        return $this->configuredForStorage
            && true === $this->enabled
            && true === $this->active
            && BackupTargetCapacityStatus::Measured === $this->capacityStatus
            && null !== $this->totalBytes
            && null !== $this->usedBytes
            && null !== $this->availableBytes
            && null !== $this->observedAt
            && [] === $this->blockers;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'nodeId' => $this->nodeId,
            'nodeName' => $this->nodeName,
            'configuredForStorage' => $this->configuredForStorage,
            'enabled' => $this->enabled,
            'active' => $this->active,
            'capacityStatus' => $this->capacityStatus->value,
            'totalBytes' => $this->totalBytes?->value,
            'usedBytes' => $this->usedBytes?->value,
            'availableBytes' => $this->availableBytes?->value,
            'observedAt' => $this->observedAt,
            'blockers' => array_column($this->blockers, 'value'),
        ];
    }

    /** @param list<BackupTargetBlockerCode> $blockers
     *  @return list<BackupTargetBlockerCode>
     */
    private static function uniqueBlockers(array $blockers): array
    {
        $seen = [];
        $result = [];
        foreach ($blockers as $blocker) {
            if (isset($seen[$blocker->value])) {
                continue;
            }
            $seen[$blocker->value] = true;
            $result[] = $blocker;
        }
        return $result;
    }

    private static function assertUuid(string $uuid): void
    {
        new ReadModelIdentifier($uuid);
    }
}
