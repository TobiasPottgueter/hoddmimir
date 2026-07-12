<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PbsBackupTargetEvidence
{
    /** @var list<BackupTargetBlockerCode> */
    public array $blockers;

    /** @param list<BackupTargetBlockerCode> $blockers */
    public function __construct(
        public string $server,
        public int $port,
        public string $datastore,
        public ?string $namespace,
        public string $mappingObservedAt,
        public PbsEndpointMatchStatus $endpointMatch,
        public ?string $pbsConnectionId,
        public ?string $pbsServerId,
        public ?string $pbsDatastoreId,
        public ?string $pbsNamespaceId,
        public ?string $capacitySemantics,
        public ?UInt64Decimal $totalBytes,
        public ?UInt64Decimal $usedBytes,
        public ?UInt64Decimal $availableBytes,
        public ?string $capacityObservedAt,
        array $blockers,
    ) {
        if ('' === $server || $port < 1 || $port > 65535 || '' === $datastore) {
            throw new InvalidArgumentException('PBS target mapping evidence is invalid.');
        }
        self::utc($mappingObservedAt);
        foreach ([$pbsConnectionId, $pbsServerId, $pbsDatastoreId, $pbsNamespaceId] as $id) {
            if (null !== $id) {
                new ReadModelIdentifier($id);
            }
        }
        $hasCapacity = null !== $totalBytes && null !== $usedBytes && null !== $availableBytes;
        if ((null !== $capacitySemantics) !== $hasCapacity
            || $hasCapacity !== (null !== $capacityObservedAt)) {
            throw new InvalidArgumentException('PBS target capacity evidence is incomplete.');
        }
        if ($hasCapacity && (!$usedBytes->lessThanOrEqual($totalBytes) || !$availableBytes->lessThanOrEqual($totalBytes))) {
            throw new InvalidArgumentException('PBS target capacity parts must not exceed total bytes.');
        }
        if (null !== $capacityObservedAt) {
            self::utc($capacityObservedAt);
        }
        $ids = [$pbsConnectionId, $pbsServerId, $pbsDatastoreId, $pbsNamespaceId];
        $requiredBlocker = null;
        if (PbsEndpointMatchStatus::Unresolved === $endpointMatch) {
            $requiredBlocker = BackupTargetBlockerCode::PbsEndpointUnresolved;
        } elseif (PbsEndpointMatchStatus::Ambiguous === $endpointMatch) {
            $requiredBlocker = BackupTargetBlockerCode::PbsEndpointAmbiguous;
        }
        if (null === $requiredBlocker) {
            if (4 !== count(array_filter($ids))) {
                throw new InvalidArgumentException('Matched PBS evidence requires complete relational inventory IDs.');
            }
        } else {
            $hasRequiredBlocker = false;
            foreach ($blockers as $blocker) {
                if ($requiredBlocker === $blocker) {
                    $hasRequiredBlocker = true;
                    break;
                }
            }
            if (!$hasRequiredBlocker) {
                throw new InvalidArgumentException('Unmatched PBS evidence must be blocked and expose no relational IDs.');
            }
            if ([] !== array_filter($ids)) {
                throw new InvalidArgumentException('Unmatched PBS evidence must be blocked and expose no relational IDs.');
            }
        }
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

    private static function utc(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new InvalidArgumentException('Target evidence timestamps must be canonical UTC.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'server' => $this->server,
            'port' => $this->port,
            'datastore' => $this->datastore,
            'namespace' => $this->namespace,
            'mappingObservedAt' => $this->mappingObservedAt,
            'endpointMatch' => $this->endpointMatch->value,
            'pbsConnectionId' => $this->pbsConnectionId,
            'pbsServerId' => $this->pbsServerId,
            'pbsDatastoreId' => $this->pbsDatastoreId,
            'pbsNamespaceId' => $this->pbsNamespaceId,
            'capacitySemantics' => $this->capacitySemantics,
            'totalBytes' => $this->totalBytes?->value,
            'usedBytes' => $this->usedBytes?->value,
            'availableBytes' => $this->availableBytes?->value,
            'capacityObservedAt' => $this->capacityObservedAt,
            'blockers' => array_column($this->blockers, 'value'),
        ];
    }
}
