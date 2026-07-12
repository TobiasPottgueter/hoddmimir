<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Inventory\Capability\CapabilitySnapshotConflict;
use App\Application\Inventory\Capability\CapabilitySnapshotObservation;
use App\Application\Inventory\Capability\CapabilityProfile;
use App\Application\Inventory\Capability\CapabilitySnapshotStore;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalCapabilitySnapshotStore implements CapabilitySnapshotStore
{
    private const string SCHEDULE_NAME = 'inventory';

    public function __construct(
        private Connection $connection,
        private InventoryIdentifierGenerator $identifierGenerator,
    ) {
    }

    public function persist(
        CollectorLease $lease,
        CapabilitySnapshotObservation $observation,
    ): InventoryIdentifier {
        return $this->connection->transactional(function (Connection $connection) use ($lease, $observation): InventoryIdentifier {
            $this->assertFence($connection, $lease, true);
            $run = $connection->fetchAssociative(
                'SELECT * FROM inventory_sync_runs WHERE id = :run AND connection_id = :connection FOR UPDATE',
                ['run' => $observation->runId->binary(), 'connection' => $observation->connectionId->binary()],
            );
            if (false === $run || ($run['status'] ?? null) !== 'running'
                || !is_string($run['cycle_token'] ?? null)
                || !hash_equals($lease->token->binary(), $run['cycle_token'])
                || $this->integer($run, 'collector_fencing_token') !== $lease->fencingToken) {
                throw new CollectorLeaseOwnershipLost('The capability run is not owned by this lease.');
            }
            if ($this->integer($run, 'expected_connection_revision') !== $observation->expectedConnectionRevision) {
                throw CapabilitySnapshotConflict::invariant('The capability revision differs from its run.');
            }
            if (!is_string($run['endpoint_id'] ?? null)
                || !hash_equals($observation->endpointId->bytes, $run['endpoint_id'])) {
                throw CapabilitySnapshotConflict::invariant('The capability endpoint was not selected by the run.');
            }

            $configuration = $connection->fetchAssociative(
                'SELECT product, enabled, revision FROM proxmox_connections WHERE id = :id FOR UPDATE',
                ['id' => $observation->connectionId->binary()],
            );
            if (false === $configuration
                || ($configuration['product'] ?? null) !== $observation->profile->product->value
                || 1 !== $this->integer($configuration, 'enabled')
                || $observation->expectedConnectionRevision !== $this->integer($configuration, 'revision')) {
                throw CapabilitySnapshotConflict::connectionChanged();
            }

            $hash = $observation->snapshotHash();
            $snapshot = $connection->fetchAssociative(
                'SELECT * FROM proxmox_capability_snapshots WHERE connection_id = :connection AND snapshot_hash = :hash FOR UPDATE',
                ['connection' => $observation->connectionId->binary(), 'hash' => $hash],
            );
            if (false === $snapshot) {
                $snapshotId = $this->identifierGenerator->generate();
                $observedAt = $this->format($observation->observedAt);
                $connection->insert('proxmox_capability_snapshots', [
                    'id' => $snapshotId->binary(),
                    'connection_id' => $observation->connectionId->binary(),
                    'endpoint_id' => $observation->endpointId->bytes,
                    'product' => $observation->profile->product->value,
                    'version_major' => $observation->profile->versionMajor,
                    'version_minor' => $observation->profile->versionMinor,
                    'version_patch' => $observation->profile->versionPatch,
                    'release_name' => $observation->profile->releaseName,
                    'raw_version' => $observation->profile->rawVersion,
                    'profile_version' => CapabilityProfile::PROFILE_VERSION,
                    'capabilities_json' => $observation->profile->capabilitiesJson(),
                    'snapshot_hash' => $hash,
                    'first_observed_at' => $observedAt,
                    'last_observed_at' => $observedAt,
                ]);
            } else {
                $snapshotId = new InventoryIdentifier($this->binary($snapshot, 'id', 16));
                $this->assertMatchingSnapshot($snapshot, $observation);
                $connection->executeStatement(
                    'UPDATE proxmox_capability_snapshots SET last_observed_at = GREATEST(last_observed_at, :observed) WHERE id = :id',
                    ['observed' => $this->format($observation->observedAt), 'id' => $snapshotId->binary()],
                );
            }

            $linked = $run['capability_snapshot_id'] ?? null;
            if (null === $linked) {
                $updated = $connection->executeStatement(
                    <<<'SQL'
                        UPDATE inventory_sync_runs
                        SET capability_snapshot_id = :snapshot, heartbeat_at = :heartbeat
                        WHERE id = :id AND status = 'running' AND capability_snapshot_id IS NULL
                        SQL,
                    [
                        'snapshot' => $snapshotId->binary(),
                        'heartbeat' => $this->format($observation->observedAt),
                        'id' => $observation->runId->binary(),
                    ],
                );
                if (1 !== $updated) {
                    throw CapabilitySnapshotConflict::invariant('The capability snapshot could not be linked exactly once.');
                }
            } elseif (!is_string($linked) || !hash_equals($snapshotId->binary(), $linked)) {
                throw CapabilitySnapshotConflict::invariant('The run already references another capability snapshot.');
            }

            $this->assertFence($connection, $lease, false);
            return $snapshotId;
        });
    }

    /** @param array<string, mixed> $snapshot */
    private function assertMatchingSnapshot(array $snapshot, CapabilitySnapshotObservation $observation): void
    {
        if (!is_string($snapshot['endpoint_id'] ?? null)
            || !hash_equals($observation->endpointId->bytes, $snapshot['endpoint_id'])
            || ($snapshot['product'] ?? null) !== $observation->profile->product->value
            || $this->integer($snapshot, 'version_major') !== $observation->profile->versionMajor
            || $this->integer($snapshot, 'version_minor') !== $observation->profile->versionMinor
            || $this->nullableInteger($snapshot['version_patch'] ?? null) !== $observation->profile->versionPatch
            || ($snapshot['release_name'] ?? null) !== $observation->profile->releaseName
            || ($snapshot['raw_version'] ?? null) !== $observation->profile->rawVersion
            || $this->integer($snapshot, 'profile_version') !== CapabilityProfile::PROFILE_VERSION
            || ($snapshot['capabilities_json'] ?? null) !== $observation->profile->capabilitiesJson()) {
            throw CapabilitySnapshotConflict::invariant('The stored capability hash has conflicting metadata.');
        }
    }

    private function assertFence(Connection $connection, CollectorLease $lease, bool $lock): void
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $schedule = $connection->fetchAssociative(
            'SELECT * FROM collector_schedule WHERE schedule_name = :name'.$suffix,
            ['name' => self::SCHEDULE_NAME],
        );
        $cycle = $connection->fetchAssociative(
            'SELECT * FROM collector_cycles WHERE cycle_token = :token'.$suffix,
            ['token' => $lease->token->binary()],
        );
        $now = $connection->fetchOne('SELECT UTC_TIMESTAMP(6)');
        if (!is_string($now)) {
            throw new RuntimeException('MariaDB did not return its UTC clock.');
        }
        $databaseNow = $this->parseDate($now);
        if (false === $schedule || false === $cycle
            || !is_string($schedule['lease_owner'] ?? null)
            || !hash_equals($lease->ownerId->bytes, $schedule['lease_owner'])
            || !is_string($schedule['lease_token'] ?? null)
            || !hash_equals($lease->token->binary(), $schedule['lease_token'])
            || $this->integer($schedule, 'lease_fencing_token') !== $lease->fencingToken
            || !is_string($schedule['lease_expires_at'] ?? null)
            || $this->parseDate($schedule['lease_expires_at']) <= $databaseNow
            || ($cycle['status'] ?? null) !== 'running'
            || !is_string($cycle['worker_instance_id'] ?? null)
            || !hash_equals($lease->ownerId->bytes, $cycle['worker_instance_id'])
            || ($cycle['worker_kind'] ?? null) !== 'collector'
            || $this->integer($cycle, 'fencing_token') !== $lease->fencingToken) {
            throw new CollectorLeaseOwnershipLost('The capability write lost its collector lease.');
        }
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $parsed) {
            throw new RuntimeException('MariaDB returned an invalid UTC timestamp.');
        }
        return $parsed;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        return $this->nullableInteger($row[$key] ?? null)
            ?? throw new RuntimeException('MariaDB returned an invalid integer.');
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key, int $length): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || strlen($value) !== $length) {
            throw new RuntimeException('MariaDB returned an invalid binary value.');
        }
        return $value;
    }
}
