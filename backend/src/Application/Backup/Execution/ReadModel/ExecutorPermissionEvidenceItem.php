<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution\ReadModel;

use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Target\ReadModel\EvidenceFreshness;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ExecutorPermissionEvidenceItem
{
    public function __construct(
        public string $id,
        public string $connectionId,
        public string $clusterId,
        public string $targetId,
        public string $nodeId,
        public string $storageId,
        public string $guestId,
        public UInt64Decimal $evidenceSetRevision,
        public string $endpointId,
        public int $connectionRevision,
        public int $backupCredentialRevision,
        public int $scanCredentialRevision,
        public string $observedAt,
        public EvidenceFreshness $freshness,
        public bool $vmBackupAuthorized,
        public bool $datastoreAllocateAuthorized,
        public bool $authorized,
    ) {
        foreach ([$id, $connectionId, $clusterId, $targetId, $nodeId, $storageId, $guestId, $endpointId] as $identifier) {
            new ReadModelIdentifier($identifier);
        }
        if ('0' === $evidenceSetRevision->value || $connectionRevision < 1
            || $backupCredentialRevision < 1 || $scanCredentialRevision < 1) {
            throw new InvalidArgumentException('Executor permission evidence revisions must be positive.');
        }
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $observedAt, new DateTimeZone('UTC'));
        if (false === $timestamp || $timestamp->format('Y-m-d\TH:i:s.u\Z') !== $observedAt) {
            throw new InvalidArgumentException('Executor permission evidence requires a canonical UTC timestamp.');
        }
        if ($authorized !== ($vmBackupAuthorized && $datastoreAllocateAuthorized)) {
            throw new InvalidArgumentException('Executor permission evidence authorization is inconsistent.');
        }
        if (EvidenceFreshness::Missing === $freshness) {
            throw new InvalidArgumentException('A projected executor permission evidence row cannot be missing.');
        }
    }

    /** @return list<string> */
    public function missingPermissions(): array
    {
        $missing = [];
        if (!$this->vmBackupAuthorized) {
            $missing[] = 'VM.Backup';
        }
        if (!$this->datastoreAllocateAuthorized) {
            $missing[] = 'Datastore.AllocateSpace';
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'connectionId' => $this->connectionId,
            'clusterId' => $this->clusterId,
            'targetId' => $this->targetId,
            'nodeId' => $this->nodeId,
            'storageId' => $this->storageId,
            'guestId' => $this->guestId,
            'evidenceSetRevision' => $this->evidenceSetRevision->value,
            'endpointId' => $this->endpointId,
            'connectionRevision' => $this->connectionRevision,
            'backupCredentialRevision' => $this->backupCredentialRevision,
            'scanCredentialRevision' => $this->scanCredentialRevision,
            'observedAt' => $this->observedAt,
            'freshness' => $this->freshness->value,
            'vmBackupAuthorized' => $this->vmBackupAuthorized,
            'datastoreAllocateAuthorized' => $this->datastoreAllocateAuthorized,
            'authorized' => $this->authorized,
            'missingPermissions' => $this->missingPermissions(),
        ];
    }
}
