<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use DateTimeImmutable;
use App\Domain\Shared\UInt64Decimal;

final readonly class AutomaticShadowCandidate
{
    public function __construct(
        public string $connectionId,
        public bool $connectionEnabled,
        public string $clusterId,
        public bool $clusterEnabled,
        public string $guestId,
        public bool $guestActive,
        public ?bool $guestTemplate,
        public DateTimeImmutable $inventoryObservedAt,
        public ?string $nodeId,
        public bool $nodeEnabled,
        public ?int $placementRevision,
        public ?DateTimeImmutable $placementObservedAt,
        public string $policyId,
        public int $policyRevision,
        public bool $policyEnabled,
        public string $policySnapshotHash,
        public bool $selectionIncluded,
        public bool $explicitlyExcluded,
        public string $targetId,
        public int $targetRevision,
        public bool $targetEnabled,
        public bool $targetNodeAllowed,
        public bool $storageEnabled,
        public bool $storageActive,
        public ?DateTimeImmutable $capacityObservedAt,
        public ?UInt64Decimal $availableBytes,
        public ?UInt64Decimal $minimumFreeBytes,
        public bool $nodeConcurrencyAvailable,
        public bool $targetConcurrencyAvailable,
        public bool $pbsMappingValid,
        public ?DateTimeImmutable $pbsObservedAt,
        public ?DateTimeImmutable $executorObservedAt,
        public bool $executorAuthorized,
        public bool $activeRequestAbsent,
        public ?DateTimeImmutable $lastSuccessAt,
        public ?int $maximumAgeSeconds,
        public ?UInt64Decimal $currentBytes,
        public ?DateTimeImmutable $writeStateObservedAt,
        public ?UInt64Decimal $baselineBytes,
        public ?UInt64Decimal $bytesThreshold,
        public ?int $cooldownSeconds,
    ) {
    }
}
