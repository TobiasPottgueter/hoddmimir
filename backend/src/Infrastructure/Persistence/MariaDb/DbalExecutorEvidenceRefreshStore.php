<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Execution\ExecutorEvidenceLeaseOwnershipLost;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshClaim;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshEndpoint;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshStore;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshSubject;
use App\Application\Backup\Execution\ExecutorPermissionProjection;
use App\Application\Backup\Queue\QueueClaimTokenSource;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use RuntimeException;

final readonly class DbalExecutorEvidenceRefreshStore implements ExecutorEvidenceRefreshStore
{
    public function __construct(
        private Connection $connection,
        private QueueClaimTokenSource $tokens,
        private int $cadenceSeconds = 120,
        private int $leaseSeconds = 90,
        private int $maximumSubjects = 65_536,
    ) {
        if ($cadenceSeconds < 1 || $cadenceSeconds > 86_400
            || $leaseSeconds < 61 || $leaseSeconds > 3_600
            || $maximumSubjects < 1 || $maximumSubjects > 262_144) {
            throw new InvalidArgumentException('Executor evidence refresh persistence limits are invalid.');
        }
    }

    public function claimDue(string $workerId, DateTimeImmutable $now): ?ExecutorEvidenceRefreshClaim
    {
        $this->binary($workerId);
        $this->utc($now);

        $claim = $this->claimExistingDueState($workerId);
        if (null !== $claim) {
            return $claim;
        }

        $missing = $this->connection->fetchOne(<<<'SQL'
SELECT catalog.connection_id
FROM executor_evidence_claim_catalog catalog
WHERE NOT EXISTS (
    SELECT 1 FROM executor_evidence_refresh_state state
    WHERE state.connection_id = catalog.connection_id
)
ORDER BY catalog.connection_id
LIMIT 1
SQL);
        if (false === $missing) {
            return null;
        }
        $connectionId = $this->binary($missing);
        $this->connection->executeStatement(<<<'SQL'
INSERT IGNORE INTO executor_evidence_refresh_state
    (connection_id, next_due_at, lease_fence, published_set_revision, updated_at)
VALUES (:connection, UTC_TIMESTAMP(6), 0, 0, UTC_TIMESTAMP(6))
SQL, ['connection' => $connectionId], ['connection' => ParameterType::BINARY]);

        return $this->claimExistingDueState($workerId);
    }

    private function claimExistingDueState(string $workerId): ?ExecutorEvidenceRefreshClaim
    {
        return $this->connection->transactional(function (Connection $db) use ($workerId): ?ExecutorEvidenceRefreshClaim {

            $state = $db->fetchAssociative(<<<'SQL'
SELECT state.*
FROM executor_evidence_refresh_state state
INNER JOIN executor_evidence_claim_catalog catalog
        ON catalog.connection_id = state.connection_id
WHERE state.next_due_at <= UTC_TIMESTAMP(6)
  AND (state.lease_owner IS NULL OR state.lease_expires_at <= UTC_TIMESTAMP(6))
ORDER BY state.next_due_at, state.connection_id
LIMIT 1
FOR UPDATE SKIP LOCKED
SQL);
            if (false === $state) {
                return null;
            }

            $dbNow = $this->databaseNow($db);
            if ($this->date($state['next_due_at'] ?? null) > $dbNow
                || (null !== ($state['lease_owner'] ?? null)
                    && $this->date($state['lease_expires_at'] ?? null) > $dbNow)) {
                return null;
            }
            $connectionId = $this->binary($state['connection_id'] ?? null);
            $catalog = $this->catalog($db, $connectionId);
            $endpoints = $db->fetchAllAssociative(<<<'SQL'
SELECT endpoint_id AS id, priority
FROM executor_evidence_endpoint_catalog
WHERE connection_id = :connection
ORDER BY priority, id
LIMIT 17
SQL, ['connection' => $connectionId], ['connection' => ParameterType::BINARY]);
            if ([] === $endpoints || \count($endpoints) > ExecutorEvidenceRefreshClaim::MAXIMUM_ENDPOINTS) {
                $this->recordConfigurationFailure($db, $connectionId, $dbNow);
                return null;
            }

            $fence = $this->integer($state['lease_fence'] ?? null);
            if ($fence >= PHP_INT_MAX) {
                throw new RuntimeException('Executor evidence refresh fence is exhausted.');
            }
            ++$fence;
            $token = $this->tokens->next();
            $this->binary($token);
            $expires = $dbNow->add(new DateInterval('PT'.$this->leaseSeconds.'S'));
            $nextDue = $dbNow->add(new DateInterval('PT'.$this->cadenceSeconds.'S'));

            $this->purgeStage($db, $connectionId);
            $affected = $db->executeStatement(<<<'SQL'
UPDATE executor_evidence_refresh_state
SET next_due_at = :next_due, lease_owner = :owner, lease_token = :token,
    lease_fence = :fence, lease_issued_at = :issued, lease_expires_at = :expires,
    lease_connection_revision = :connection_revision,
    lease_backup_credential_revision = :backup_revision,
    lease_scan_credential_revision = :scan_revision,
    lease_endpoint_id = NULL, lease_observed_at = NULL,
    staged_subject_count = 0, last_attempted_at = :issued, updated_at = :issued
WHERE connection_id = :connection
SQL, [
                'next_due' => $this->format($nextDue), 'owner' => $workerId, 'token' => $token,
                'fence' => $fence, 'issued' => $this->format($dbNow), 'expires' => $this->format($expires),
                'connection_revision' => $catalog['connection'], 'backup_revision' => $catalog['backup'],
                'scan_revision' => $catalog['scan'], 'connection' => $connectionId,
            ], ['owner' => ParameterType::BINARY, 'token' => ParameterType::BINARY, 'connection' => ParameterType::BINARY]);
            if (1 !== $affected) {
                throw new RuntimeException('Executor evidence refresh state changed while locked.');
            }

            $this->materializeSubjects($db, $connectionId, $fence);
            $count = $this->integer($db->fetchOne(
                'SELECT COUNT(*) FROM executor_evidence_refresh_subject_stage WHERE connection_id = :connection AND evidence_set_revision = :fence',
                ['connection' => $connectionId, 'fence' => $fence],
                ['connection' => ParameterType::BINARY],
            ));
            if ($count > $this->maximumSubjects) {
                $this->releaseAsConfigurationFailure($db, $connectionId, $workerId, $token, $fence, $dbNow);
                return null;
            }
            $db->executeStatement(
                'UPDATE executor_evidence_refresh_state SET staged_subject_count = :count WHERE connection_id = :connection',
                ['count' => $count, 'connection' => $connectionId],
                ['connection' => ParameterType::BINARY],
            );

            return new ExecutorEvidenceRefreshClaim(
                $connectionId,
                $catalog['connection'],
                $catalog['backup'],
                $catalog['scan'],
                $workerId,
                $token,
                $fence,
                $expires,
                \array_map(fn (array $row): ExecutorEvidenceRefreshEndpoint => new ExecutorEvidenceRefreshEndpoint(
                    $this->binary($row['id'] ?? null),
                    $this->integer($row['priority'] ?? null),
                ), $endpoints),
            );
        });
    }

    public function renew(ExecutorEvidenceRefreshClaim $claim, DateTimeImmutable $now): void
    {
        $this->utc($now);
        $this->connection->transactional(function (Connection $db) use ($claim): void {
            $dbNow = $this->assertLease($db, $claim);
            $affected = $db->executeStatement(<<<'SQL'
UPDATE executor_evidence_refresh_state
SET lease_expires_at = :expires, updated_at = :now
WHERE connection_id = :connection AND lease_owner = :owner AND lease_token = :token AND lease_fence = :fence
SQL, [
                'expires' => $this->format($dbNow->add(new DateInterval('PT'.$this->leaseSeconds.'S'))),
                'now' => $this->format($dbNow), 'connection' => $claim->connectionId,
                'owner' => $claim->leaseOwner, 'token' => $claim->leaseToken, 'fence' => $claim->leaseFence,
            ], ['connection' => ParameterType::BINARY, 'owner' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
            if (1 !== $affected) {
                throw new ExecutorEvidenceLeaseOwnershipLost();
            }
        });
    }

    public function bindSnapshotEndpoint(
        ExecutorEvidenceRefreshClaim $claim,
        string $endpointId,
        DateTimeImmutable $observedAt,
    ): void {
        $this->utc($observedAt);
        $claimed = false;
        foreach ($claim->endpoints as $endpoint) {
            if (hash_equals($endpoint->id, $endpointId)) {
                $claimed = true;
                break;
            }
        }
        if (!$claimed) {
            throw new InvalidArgumentException('Executor evidence snapshot endpoint is not part of its claim.');
        }

        $this->connection->transactional(function (Connection $db) use ($claim, $endpointId, $observedAt): void {
            $dbNow = $this->assertLease($db, $claim);
            $binding = $db->fetchAssociative(<<<'SQL'
SELECT lease_endpoint_id, lease_observed_at
FROM executor_evidence_refresh_state
WHERE connection_id = :connection
FOR UPDATE
SQL, ['connection' => $claim->connectionId], ['connection' => ParameterType::BINARY]);
            if (false === $binding) {
                throw new ExecutorEvidenceLeaseOwnershipLost();
            }
            if (null !== ($binding['lease_endpoint_id'] ?? null)
                || null !== ($binding['lease_observed_at'] ?? null)) {
                if (!$this->sameBinary($binding['lease_endpoint_id'] ?? null, $endpointId)
                    || $this->date($binding['lease_observed_at'] ?? null) != $observedAt) {
                    throw new InvalidArgumentException('Executor evidence snapshot binding cannot change.');
                }
                return;
            }
            $affected = $db->executeStatement(<<<'SQL'
UPDATE executor_evidence_refresh_state
SET lease_endpoint_id = :endpoint, lease_observed_at = :observed, updated_at = :updated
WHERE connection_id = :connection AND lease_owner = :owner AND lease_token = :token
  AND lease_fence = :fence AND lease_endpoint_id IS NULL AND lease_observed_at IS NULL
SQL, [
                'endpoint' => $endpointId,
                'observed' => $this->format($observedAt),
                'updated' => $this->format($dbNow),
                'connection' => $claim->connectionId,
                'owner' => $claim->leaseOwner,
                'token' => $claim->leaseToken,
                'fence' => $claim->leaseFence,
            ], [
                'endpoint' => ParameterType::BINARY,
                'connection' => ParameterType::BINARY,
                'owner' => ParameterType::BINARY,
                'token' => ParameterType::BINARY,
                'fence' => ParameterType::INTEGER,
            ]);
            if (1 !== $affected) {
                throw new ExecutorEvidenceLeaseOwnershipLost();
            }
        });
    }

    public function subjects(ExecutorEvidenceRefreshClaim $claim, ?string $afterSubjectKey, int $limit): array
    {
        if ($limit < 1 || $limit > 1_024 || (null !== $afterSubjectKey && 48 !== \strlen($afterSubjectKey))) {
            throw new InvalidArgumentException('Executor evidence subject page request is invalid.');
        }

        return $this->connection->transactional(function (Connection $db) use ($claim, $afterSubjectKey, $limit): array {
            $this->assertLease($db, $claim);
            $afterTarget = null === $afterSubjectKey ? \str_repeat("\0", 16) : \substr($afterSubjectKey, 0, 16);
            $afterNode = null === $afterSubjectKey ? \str_repeat("\0", 16) : \substr($afterSubjectKey, 16, 16);
            $afterGuest = null === $afterSubjectKey ? \str_repeat("\0", 16) : \substr($afterSubjectKey, 32, 16);
            $rows = $db->fetchAllAssociative(<<<'SQL'
SELECT connection_id, cluster_id, target_id, node_id, storage_id, storage_name, guest_id, vmid
FROM executor_evidence_refresh_subject_stage
WHERE connection_id = :connection AND evidence_set_revision = :fence
  AND (target_id, node_id, guest_key) > (:after_target, :after_node, :after_guest)
ORDER BY target_id, node_id, guest_key
LIMIT :page_limit
SQL, [
                'connection' => $claim->connectionId, 'fence' => $claim->leaseFence,
                'after_target' => $afterTarget, 'after_node' => $afterNode, 'after_guest' => $afterGuest,
                'page_limit' => $limit,
            ], [
                'connection' => ParameterType::BINARY, 'after_target' => ParameterType::BINARY,
                'after_node' => ParameterType::BINARY, 'after_guest' => ParameterType::BINARY,
                'page_limit' => ParameterType::INTEGER,
            ]);

            return \array_map(fn (array $row): ExecutorEvidenceRefreshSubject => new ExecutorEvidenceRefreshSubject(
                $this->binary($row['connection_id'] ?? null),
                $this->binary($row['cluster_id'] ?? null),
                $this->binary($row['target_id'] ?? null),
                $this->binary($row['node_id'] ?? null),
                $this->binary($row['storage_id'] ?? null),
                $this->text($row['storage_name'] ?? null),
                null === ($row['guest_id'] ?? null) ? null : $this->binary($row['guest_id']),
                null === ($row['vmid'] ?? null) ? null : $this->integer($row['vmid']),
            ), $rows);
        });
    }

    public function stage(ExecutorEvidenceRefreshClaim $claim, array $projections): void
    {
        if ([] === $projections || \count($projections) > 1_024) {
            throw new InvalidArgumentException('Executor evidence projection page is invalid.');
        }
        $endpointIds = [];
        foreach ($claim->endpoints as $claimedEndpoint) {
            $endpointIds[$claimedEndpoint->id] = true;
        }
        $selectedEndpointId = null;
        foreach ($projections as $projection) {
            /** @phpstan-ignore instanceof.alwaysTrue (enforce the external interface boundary at runtime) */
            if (!$projection instanceof ExecutorPermissionProjection
                || $projection->subject->connectionId !== $claim->connectionId
                || !isset($endpointIds[$projection->endpointId])
                || $projection->connectionRevision !== $claim->connectionRevision
                || $projection->backupCredentialRevision !== $claim->backupCredentialRevision
                || $projection->scanCredentialRevision !== $claim->scanCredentialRevision) {
                throw new InvalidArgumentException('Executor evidence projection does not match its claim.');
            }
            if (null !== $selectedEndpointId && !hash_equals($selectedEndpointId, $projection->endpointId)) {
                throw new InvalidArgumentException('Executor evidence projection pages cannot mix endpoints.');
            }
            $selectedEndpointId = $projection->endpointId;
        }

        $this->connection->transactional(function (Connection $db) use ($claim, $projections, $selectedEndpointId): void {
            $this->assertLease($db, $claim);
            $binding = $db->fetchAssociative(<<<'SQL'
SELECT lease_endpoint_id
FROM executor_evidence_refresh_state
WHERE connection_id = :connection AND lease_fence = :fence
SQL, [
                'connection' => $claim->connectionId,
                'fence' => $claim->leaseFence,
            ], ['connection' => ParameterType::BINARY, 'fence' => ParameterType::INTEGER]);
            if (false === $binding
                || !$this->sameBinary($binding['lease_endpoint_id'] ?? null, $selectedEndpointId)) {
                throw new InvalidArgumentException('Executor evidence set cannot mix endpoints.');
            }
            $values = [];
            $parameters = [];
            $types = [];
            foreach ($projections as $index => $projection) {
                $subject = $projection->subject;
                $keys = [
                    'connection' => $claim->connectionId,
                    'set_revision' => $claim->leaseFence,
                    'target' => $subject->targetId,
                    'node' => $subject->nodeId,
                    'guest_key' => $subject->guestId ?? \str_repeat("\0", 16),
                    'endpoint' => $projection->endpointId,
                    'connection_revision' => $claim->connectionRevision,
                    'backup_revision' => $claim->backupCredentialRevision,
                    'scan_revision' => $claim->scanCredentialRevision,
                    'vm' => $projection->vmBackupAuthorized ? 1 : 0,
                    'storage' => $projection->datastoreAllocateAuthorized ? 1 : 0,
                    'authorized' => $projection->authorized() ? 1 : 0,
                ];
                $names = [];
                foreach ($keys as $key => $value) {
                    $name = $key.'_'.$index;
                    $names[] = ':'.$name;
                    $parameters[$name] = $value;
                    if (\in_array($key, ['connection', 'target', 'node', 'guest_key', 'endpoint'], true)) {
                        $types[$name] = ParameterType::BINARY;
                    }
                }
                $values[] = '('.\implode(', ', $names).')';
            }
            $valueSql = \implode(",\n    ", $values);
            $db->executeStatement(<<<SQL
INSERT INTO executor_evidence_refresh_projection_stage
    (connection_id, evidence_set_revision, target_id, node_id, guest_key, endpoint_id,
     connection_revision, backup_credential_revision, scan_credential_revision,
     vm_backup_authorized, datastore_allocate_authorized, authorized)
VALUES
    {$valueSql}
ON DUPLICATE KEY UPDATE
    endpoint_id = VALUES(endpoint_id), connection_revision = VALUES(connection_revision),
    backup_credential_revision = VALUES(backup_credential_revision),
    scan_credential_revision = VALUES(scan_credential_revision),
    vm_backup_authorized = VALUES(vm_backup_authorized),
    datastore_allocate_authorized = VALUES(datastore_allocate_authorized),
    authorized = VALUES(authorized)
SQL, $parameters, $types);
        });
    }

    public function publish(ExecutorEvidenceRefreshClaim $claim, DateTimeImmutable $observedAt): void
    {
        $this->utc($observedAt);
        $this->connection->transactional(function (Connection $db) use ($claim, $observedAt): void {
            $dbNow = $this->assertLease($db, $claim);
            $bound = $db->fetchAssociative(<<<'SQL'
SELECT lease_endpoint_id, lease_observed_at
FROM executor_evidence_refresh_state
WHERE connection_id = :connection AND lease_fence = :fence
FOR UPDATE
SQL, [
                'connection' => $claim->connectionId,
                'fence' => $claim->leaseFence,
            ], ['connection' => ParameterType::BINARY, 'fence' => ParameterType::INTEGER]);
            if (false === $bound || null === ($bound['lease_endpoint_id'] ?? null)
                || $this->date($bound['lease_observed_at'] ?? null) != $observedAt) {
                throw new InvalidArgumentException('Executor evidence publication does not match its snapshot binding.');
            }
            $missing = $db->fetchOne(<<<'SQL'
SELECT 1
FROM executor_evidence_refresh_subject_stage subject
LEFT JOIN executor_evidence_refresh_projection_stage projection
  ON projection.connection_id = subject.connection_id
 AND projection.evidence_set_revision = subject.evidence_set_revision
 AND projection.target_id = subject.target_id
 AND projection.node_id = subject.node_id
 AND projection.guest_key = subject.guest_key
WHERE subject.connection_id = :connection AND subject.evidence_set_revision = :fence
  AND projection.connection_id IS NULL
LIMIT 1
SQL, ['connection' => $claim->connectionId, 'fence' => $claim->leaseFence], ['connection' => ParameterType::BINARY]);
            if (false !== $missing) {
                throw new RuntimeException('Executor evidence projection stage is incomplete.');
            }
            $foreignEndpoint = $db->fetchOne(<<<'SQL'
SELECT 1
FROM executor_evidence_refresh_projection_stage
WHERE connection_id = :connection AND evidence_set_revision = :fence
  AND endpoint_id <> :endpoint
LIMIT 1
SQL, [
                'connection' => $claim->connectionId,
                'fence' => $claim->leaseFence,
                'endpoint' => $bound['lease_endpoint_id'],
            ], [
                'connection' => ParameterType::BINARY,
                'fence' => ParameterType::INTEGER,
                'endpoint' => ParameterType::BINARY,
            ]);
            if (false !== $foreignEndpoint) {
                throw new RuntimeException('Executor evidence projection stage contains mixed endpoints.');
            }

            $db->executeStatement(
                'CALL delete_executor_evidence_publish_rows(:connection)',
                ['connection' => $claim->connectionId],
                ['connection' => ParameterType::BINARY],
            );
            $db->executeStatement(<<<'SQL'
INSERT INTO executor_permission_evidence
    (id, connection_id, cluster_id, target_id, node_id, storage_id, guest_id,
     evidence_set_revision, endpoint_id, connection_revision,
     backup_credential_revision, scan_credential_revision,
     vm_backup_authorized, datastore_allocate_authorized, authorized, observed_at, revision)
SELECT
    UNHEX(SUBSTR(SHA2(CONCAT('executor-evidence', subject.connection_id, subject.target_id,
        subject.node_id, subject.guest_key), 256), 1, 32)),
    subject.connection_id, subject.cluster_id, subject.target_id, subject.node_id,
    subject.storage_id, subject.guest_id, subject.evidence_set_revision,
    projection.endpoint_id, projection.connection_revision,
    projection.backup_credential_revision, projection.scan_credential_revision,
    projection.vm_backup_authorized, projection.datastore_allocate_authorized,
    projection.authorized, :observed, subject.evidence_set_revision
FROM executor_evidence_refresh_subject_stage subject
INNER JOIN executor_evidence_refresh_projection_stage projection
        ON projection.connection_id = subject.connection_id
       AND projection.evidence_set_revision = subject.evidence_set_revision
       AND projection.target_id = subject.target_id
       AND projection.node_id = subject.node_id
       AND projection.guest_key = subject.guest_key
WHERE subject.connection_id = :connection AND subject.evidence_set_revision = :fence
SQL, [
                'observed' => $this->format($observedAt),
                'connection' => $claim->connectionId,
                'fence' => $claim->leaseFence,
            ], ['connection' => ParameterType::BINARY]);

            $affected = $db->executeStatement(<<<'SQL'
UPDATE executor_evidence_refresh_state
SET published_set_revision = :fence,
    published_endpoint_id = lease_endpoint_id,
    published_connection_revision = lease_connection_revision,
    published_backup_credential_revision = lease_backup_credential_revision,
    published_scan_credential_revision = lease_scan_credential_revision,
    lease_owner = NULL, lease_token = NULL, lease_issued_at = NULL, lease_expires_at = NULL,
    lease_connection_revision = NULL, lease_backup_credential_revision = NULL,
    lease_scan_credential_revision = NULL, lease_endpoint_id = NULL, lease_observed_at = NULL,
    staged_subject_count = NULL,
    last_success_at = :observed, last_failure_code = NULL, updated_at = :now
WHERE connection_id = :connection AND lease_owner = :owner AND lease_token = :token AND lease_fence = :fence
SQL, [
                'fence' => $claim->leaseFence, 'observed' => $this->format($observedAt),
                'now' => $this->format($dbNow), 'connection' => $claim->connectionId,
                'owner' => $claim->leaseOwner, 'token' => $claim->leaseToken,
            ], ['connection' => ParameterType::BINARY, 'owner' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
            if (1 !== $affected) {
                throw new ExecutorEvidenceLeaseOwnershipLost();
            }
            $this->purgeStage($db, $claim->connectionId);
        });
    }

    public function fail(
        ExecutorEvidenceRefreshClaim $claim,
        ExecutorEvidenceRefreshFailureCode $code,
        DateTimeImmutable $now,
    ): void {
        $this->utc($now);
        $this->connection->transactional(function (Connection $db) use ($claim, $code): void {
            $dbNow = $this->assertLease($db, $claim);
            $affected = $db->executeStatement(<<<'SQL'
UPDATE executor_evidence_refresh_state
SET lease_owner = NULL, lease_token = NULL, lease_issued_at = NULL, lease_expires_at = NULL,
    lease_connection_revision = NULL, lease_backup_credential_revision = NULL,
    lease_scan_credential_revision = NULL, lease_endpoint_id = NULL, lease_observed_at = NULL,
    staged_subject_count = NULL,
    last_failure_code = :failure, updated_at = :now
WHERE connection_id = :connection AND lease_owner = :owner AND lease_token = :token AND lease_fence = :fence
SQL, [
                'failure' => $code->value, 'now' => $this->format($dbNow),
                'connection' => $claim->connectionId, 'owner' => $claim->leaseOwner,
                'token' => $claim->leaseToken, 'fence' => $claim->leaseFence,
            ], ['connection' => ParameterType::BINARY, 'owner' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
            if (1 !== $affected) {
                throw new ExecutorEvidenceLeaseOwnershipLost();
            }
            $this->purgeStage($db, $claim->connectionId);
        });
    }

    private function assertLease(Connection $db, ExecutorEvidenceRefreshClaim $claim): DateTimeImmutable
    {
        $state = $db->fetchAssociative(
            'SELECT * FROM executor_evidence_refresh_state WHERE connection_id = :connection FOR UPDATE',
            ['connection' => $claim->connectionId],
            ['connection' => ParameterType::BINARY],
        );
        if (false === $state) {
            throw new ExecutorEvidenceLeaseOwnershipLost();
        }
        $dbNow = $this->databaseNow($db);
        if (!$this->sameBinary($state['lease_owner'] ?? null, $claim->leaseOwner)
            || !$this->sameBinary($state['lease_token'] ?? null, $claim->leaseToken)
            || $this->integer($state['lease_fence'] ?? null) !== $claim->leaseFence
            || $this->date($state['lease_expires_at'] ?? null) <= $dbNow
            || $this->integer($state['lease_connection_revision'] ?? null) !== $claim->connectionRevision
            || $this->integer($state['lease_backup_credential_revision'] ?? null) !== $claim->backupCredentialRevision
            || $this->integer($state['lease_scan_credential_revision'] ?? null) !== $claim->scanCredentialRevision) {
            throw new ExecutorEvidenceLeaseOwnershipLost();
        }
        $catalog = $this->catalog($db, $claim->connectionId);
        if ($catalog['connection'] !== $claim->connectionRevision
            || $catalog['backup'] !== $claim->backupCredentialRevision
            || $catalog['scan'] !== $claim->scanCredentialRevision) {
            throw new ExecutorEvidenceLeaseOwnershipLost();
        }

        return $dbNow;
    }

    /** @return array{connection: int, backup: int, scan: int} */
    private function catalog(Connection $db, string $connectionId): array
    {
        $row = $db->fetchAssociative(<<<'SQL'
SELECT connection_revision, backup_credential_revision, scan_credential_revision
FROM executor_evidence_claim_catalog
WHERE connection_id = :connection
LIMIT 1
SQL, ['connection' => $connectionId], ['connection' => ParameterType::BINARY]);
        if (false === $row) {
            throw new ExecutorEvidenceLeaseOwnershipLost();
        }
        return [
            'connection' => $this->integer($row['connection_revision'] ?? null),
            'backup' => $this->integer($row['backup_credential_revision'] ?? null),
            'scan' => $this->integer($row['scan_credential_revision'] ?? null),
        ];
    }

    private function materializeSubjects(Connection $db, string $connectionId, int $fence): void
    {
        $db->executeStatement(<<<'SQL'
INSERT INTO executor_evidence_refresh_subject_stage
    (connection_id, evidence_set_revision, cluster_id, target_id, node_id,
     storage_id, storage_name, guest_id, guest_key, vmid)
SELECT connection_id, :fence, cluster_id, target_id, node_id, storage_id,
       storage_name, guest_id, guest_key, vmid
FROM executor_evidence_subject_catalog
WHERE connection_id = :connection
ORDER BY target_id, node_id, guest_key
LIMIT :subject_limit
SQL, [
            'connection' => $connectionId,
            'fence' => $fence,
            'subject_limit' => $this->maximumSubjects + 1,
        ], [
            'connection' => ParameterType::BINARY,
            'fence' => ParameterType::INTEGER,
            'subject_limit' => ParameterType::INTEGER,
        ]);
    }

    private function recordConfigurationFailure(Connection $db, string $connectionId, DateTimeImmutable $now): void
    {
        $this->purgeStage($db, $connectionId);
        $db->executeStatement(<<<'SQL'
UPDATE executor_evidence_refresh_state
SET next_due_at = :next_due, lease_owner = NULL, lease_token = NULL,
    lease_issued_at = NULL, lease_expires_at = NULL,
    lease_connection_revision = NULL, lease_backup_credential_revision = NULL,
    lease_scan_credential_revision = NULL, lease_endpoint_id = NULL, lease_observed_at = NULL,
    staged_subject_count = NULL,
    last_attempted_at = :now, last_failure_code = 'configuration_changed', updated_at = :now
WHERE connection_id = :connection
SQL, [
            'next_due' => $this->format($now->add(new DateInterval('PT'.$this->cadenceSeconds.'S'))),
            'now' => $this->format($now),
            'connection' => $connectionId,
        ], ['connection' => ParameterType::BINARY]);
    }

    private function releaseAsConfigurationFailure(
        Connection $db,
        string $connectionId,
        string $owner,
        string $token,
        int $fence,
        DateTimeImmutable $now,
    ): void {
        $this->purgeStage($db, $connectionId);
        $affected = $db->executeStatement(<<<'SQL'
UPDATE executor_evidence_refresh_state
SET lease_owner = NULL, lease_token = NULL, lease_issued_at = NULL, lease_expires_at = NULL,
    lease_connection_revision = NULL, lease_backup_credential_revision = NULL,
    lease_scan_credential_revision = NULL, lease_endpoint_id = NULL, lease_observed_at = NULL,
    staged_subject_count = NULL,
    last_failure_code = 'configuration_changed', updated_at = :now
WHERE connection_id = :connection AND lease_owner = :owner AND lease_token = :token AND lease_fence = :fence
SQL, [
            'now' => $this->format($now),
            'connection' => $connectionId,
            'owner' => $owner,
            'token' => $token,
            'fence' => $fence,
        ], [
            'connection' => ParameterType::BINARY,
            'owner' => ParameterType::BINARY,
            'token' => ParameterType::BINARY,
            'fence' => ParameterType::INTEGER,
        ]);
        if (1 !== $affected) {
            throw new ExecutorEvidenceLeaseOwnershipLost();
        }
    }

    private function purgeStage(Connection $db, string $connectionId): void
    {
        $parameters = ['connection' => $connectionId];
        $types = ['connection' => ParameterType::BINARY];
        $db->executeStatement('DELETE FROM executor_evidence_refresh_projection_stage WHERE connection_id = :connection', $parameters, $types);
        $db->executeStatement('DELETE FROM executor_evidence_refresh_subject_stage WHERE connection_id = :connection', $parameters, $types);
    }

    private function databaseNow(Connection $db): DateTimeImmutable
    {
        return $this->date($db->fetchOne('SELECT UTC_TIMESTAMP(6)'));
    }

    private function sameBinary(mixed $actual, string $expected): bool
    {
        return \is_string($actual) && \strlen($actual) === \strlen($expected) && \hash_equals($actual, $expected);
    }

    private function binary(mixed $value): string
    {
        if (!\is_string($value) || 16 !== \strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence identifier.');
        }
        return $value;
    }

    private function integer(mixed $value): int
    {
        if (\is_int($value) && $value >= 0) {
            return $value;
        }
        if (\is_string($value) && 1 === \preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $integer = \filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (false !== $integer) {
                return $integer;
            }
        }
        throw new RuntimeException('MariaDB returned an invalid executor evidence integer.');
    }

    private function text(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence text value.');
        }
        return $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d H:i:s.u') !== $value) {
            throw new RuntimeException('MariaDB returned an invalid executor evidence timestamp.');
        }
        return $date;
    }

    private function utc(DateTimeImmutable $value): void
    {
        if (0 !== $value->getOffset()) {
            throw new InvalidArgumentException('Executor evidence persistence requires UTC timestamps.');
        }
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
