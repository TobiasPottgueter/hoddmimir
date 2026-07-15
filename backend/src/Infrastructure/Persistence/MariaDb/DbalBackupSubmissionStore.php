<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Execution\BackupSubmissionTransaction;
use App\Application\Backup\Execution\DefinitiveBackupFailureNotice;
use App\Application\Backup\Execution\ExistingSubmissionStatus;
use App\Application\Backup\Execution\PreparedBackupSubmission;
use App\Application\Backup\Execution\SubmissionPreparation;
use App\Application\Backup\Execution\SubmissionPreparationStatus;
use App\Application\Backup\Execution\SubmitClaimedBackupCommand;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupCompression;
use App\Application\Proxmox\Pve\PveBackupFailureRecipients;
use App\Application\Proxmox\Pve\PveBackupMode;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PvePruneBackups;
use App\Application\Proxmox\Pve\PveUpid;
use App\Domain\Backup\BackupProblemCode;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use JsonException;
use RuntimeException;

final readonly class DbalBackupSubmissionStore implements BackupSubmissionTransaction
{
    private DbalBackupRequestGuestGuard $guestGuard;

    public function __construct(
        private Connection $connection,
        private DbalBackupProblemRecorder $problems,
        private int $freshnessSeconds = 300,
    ) {
        if ($freshnessSeconds < 1 || $freshnessSeconds > 86_400) throw new \InvalidArgumentException('Invalid submission freshness window.');
        $this->guestGuard = new DbalBackupRequestGuestGuard();
    }

    public function inspectExistingSubmission(SubmitClaimedBackupCommand $command): ExistingSubmissionStatus
    {
        $row = $this->connection->fetchAssociative('SELECT state, run_id, claim_token, claim_fence FROM backup_requests WHERE id = :id', ['id' => $command->requestId], ['id' => ParameterType::BINARY]);
        if (false === $row || !is_string($row['claim_token'] ?? null) || !hash_equals($command->claimToken, $row['claim_token'])
            || $command->claimFence !== $this->integer($row['claim_fence'] ?? null)) {
            return ExistingSubmissionStatus::RecoveryRequired;
        }
        return 'leased' === ($row['state'] ?? null) && null === ($row['run_id'] ?? null)
            ? ExistingSubmissionStatus::FreshClaim
            : ExistingSubmissionStatus::RecoveryRequired;
    }

    public function prepareAfterFullRevalidation(SubmitClaimedBackupCommand $command): SubmissionPreparation
    {
        return $this->connection->transactional(function (Connection $db) use ($command): SubmissionPreparation {
            $row = $db->fetchAssociative(<<<'SQL'
SELECT request.*, connection.enabled AS connection_enabled,
       cluster.inventory_state AS cluster_state, cluster.last_seen_at AS cluster_seen,
       guest.name AS guest_name, guest.guest_type, guest.vmid, guest.inventory_state AS guest_state,
       guest.last_seen_at AS guest_seen, guest.is_template,
       placement.node_id AS current_node_id, placement.placement_revision AS current_placement_revision,
       placement.observed_at AS placement_seen,
       node.node_name, node.api_status, node.inventory_state AS node_state, node.last_seen_at AS node_seen,
       policy.status AS policy_status, policy.revision AS current_policy_revision,
       target.status AS target_status, target.revision AS current_target_revision,
       target.display_name AS target_label, target.minimum_free_bytes, target.fixed_parallel_limit,
       target.pbs_connection_id,
       (SELECT capability.version_major FROM proxmox_capability_snapshots capability
         WHERE capability.connection_id = request.connection_id AND capability.product = 'pve'
         ORDER BY capability.last_observed_at DESC, capability.id DESC LIMIT 1) AS current_pve_major,
       allowed.node_id AS allowed_node_id,
       storage.id AS storage_id, storage.storage_name, storage.storage_type AS target_storage_type,
       storage.supports_backup,
       storage.disabled AS storage_disabled, storage.inventory_state AS storage_state,
       storage.last_seen_at AS storage_seen,
       node_storage.enabled AS storage_enabled, node_storage.active AS storage_active,
       node_storage.available_bytes, node_storage.observed_at AS capacity_seen,
       evidence.authorized AS executor_authorized, evidence.observed_at AS executor_seen,
       pbs_datastore.allows_backup_writes AS pbs_writes,
       pbs_datastore.inventory_state AS pbs_state,
       pbs_datastore.last_seen_at AS pbs_inventory_seen,
       pbs_capacity.semantics AS pbs_capacity_semantics,
       pbs_capacity.available_bytes AS pbs_available_bytes,
       pbs_capacity.observed_at AS pbs_capacity_seen,
       mapping.observed_at AS pbs_mapping_seen,
       (pbs_connection.enabled = 1 AND pbs_connection.product = 'pbs') AS pbs_connection_enabled,
       (mapping.storage_id IS NOT NULL
         AND mapping.datastore = pbs_datastore.datastore_name
         AND EXISTS(SELECT 1 FROM proxmox_connection_endpoints pbs_endpoint
             WHERE pbs_endpoint.connection_id = target.pbs_connection_id
               AND pbs_endpoint.enabled = 1 AND pbs_endpoint.host = mapping.server AND pbs_endpoint.port = mapping.port)
         AND ((target.pbs_namespace_id IS NULL AND mapping.namespace IS NULL)
           OR (target.pbs_namespace_id IS NOT NULL AND pbs_namespace.id = target.pbs_namespace_id
             AND pbs_namespace.inventory_state = 'active' AND mapping.namespace = pbs_namespace.namespace_path))) AS pbs_mapping_valid,
       EXISTS(SELECT 1 FROM backup_policy_assignments assignment
         WHERE assignment.policy_id = request.policy_id AND assignment.status = 'active'
           AND assignment.selection_value = 'include'
           AND (assignment.scope = 'global'
             OR (assignment.scope = 'connection' AND assignment.subject_connection_id = request.connection_id)
             OR (assignment.scope = 'cluster' AND assignment.subject_cluster_id = request.cluster_id)
             OR (assignment.scope = 'node' AND assignment.node_id = placement.node_id)
             OR (assignment.scope = 'guest' AND assignment.guest_id = request.guest_id))) AS selection_included,
       EXISTS(SELECT 1 FROM backup_policy_assignments assignment
         WHERE assignment.policy_id = request.policy_id AND assignment.status = 'active'
           AND assignment.selection_value = 'exclude'
           AND (assignment.scope = 'global'
             OR (assignment.scope = 'connection' AND assignment.subject_connection_id = request.connection_id)
             OR (assignment.scope = 'cluster' AND assignment.subject_cluster_id = request.cluster_id)
             OR (assignment.scope = 'node' AND assignment.node_id = placement.node_id)
             OR (assignment.scope = 'guest' AND assignment.guest_id = request.guest_id))) AS selection_excluded,
       NOT EXISTS(SELECT 1 FROM backup_requests active_request
         WHERE active_request.guest_id = request.guest_id AND active_request.id <> request.id
           AND active_request.state IN ('leased', 'starting', 'running', 'reconcile_required')) AS active_request_absent,
       credential.principal AS submission_user
FROM backup_requests request
JOIN proxmox_connections connection ON connection.id = request.connection_id
JOIN pve_clusters cluster ON cluster.connection_id = request.connection_id AND cluster.id = request.cluster_id
JOIN guests guest ON guest.connection_id = request.connection_id AND guest.cluster_id = request.cluster_id AND guest.id = request.guest_id
JOIN guest_placements placement ON placement.connection_id = request.connection_id AND placement.cluster_id = request.cluster_id AND placement.guest_id = request.guest_id
JOIN pve_nodes node ON node.connection_id = request.connection_id AND node.cluster_id = request.cluster_id AND node.id = placement.node_id
JOIN backup_policies policy ON policy.connection_id = request.connection_id AND policy.cluster_id = request.cluster_id AND policy.id = request.policy_id
JOIN backup_targets target ON target.connection_id = request.connection_id AND target.cluster_id = request.cluster_id AND target.id = request.target_id
JOIN pve_storages storage ON storage.connection_id = request.connection_id AND storage.cluster_id = request.cluster_id AND storage.id = target.storage_id
LEFT JOIN backup_target_allowed_nodes allowed ON allowed.target_id = target.id AND allowed.node_id = placement.node_id
LEFT JOIN pve_node_storage_state node_storage ON node_storage.node_id = placement.node_id AND node_storage.storage_id = storage.id
LEFT JOIN executor_permission_evidence evidence ON evidence.connection_id = request.connection_id
 AND evidence.cluster_id = request.cluster_id AND evidence.target_id = request.target_id
 AND evidence.node_id = placement.node_id AND evidence.storage_id = storage.id AND evidence.guest_id = request.guest_id
LEFT JOIN pbs_datastores pbs_datastore ON pbs_datastore.connection_id = target.pbs_connection_id AND pbs_datastore.id = target.pbs_datastore_id
LEFT JOIN pbs_datastore_capacity_state pbs_capacity ON pbs_capacity.datastore_id = target.pbs_datastore_id
LEFT JOIN proxmox_connections pbs_connection ON pbs_connection.id = target.pbs_connection_id
LEFT JOIN pve_storage_pbs_mappings mapping ON mapping.storage_id = target.storage_id
LEFT JOIN pbs_namespaces pbs_namespace ON pbs_namespace.datastore_id = target.pbs_datastore_id AND pbs_namespace.id = target.pbs_namespace_id
JOIN backup_credentials credential ON credential.connection_id = request.connection_id
WHERE request.id = :request FOR UPDATE
SQL, ['request' => $command->requestId], ['request' => ParameterType::BINARY]);
            if (false === $row || !$this->authority($row, $command)) return new SubmissionPreparation(SubmissionPreparationStatus::RecoveryRequired);
            if (null !== ($row['cancel_requested_at'] ?? null)) {
                $this->cancelBeforeSubmission($db, $command);
                return new SubmissionPreparation(SubmissionPreparationStatus::Blocked, 'cancel_requested');
            }
            $blocker = $this->blocker($row, $command->now);
            if (null !== $blocker) {
                $this->deferBlockedClaim($db, $command, $blocker);
                return new SubmissionPreparation(SubmissionPreparationStatus::Blocked, $blocker);
            }
            $resourceBlocker = $this->lockedResourceBlocker($db, $row);
            if (null !== $resourceBlocker) {
                $this->deferBlockedClaim($db, $command, $resourceBlocker);
                return new SubmissionPreparation(SubmissionPreparationStatus::Blocked, $resourceBlocker);
            }
            $payload = $this->payload($row);
            $windowStart = $command->now->modify('-30 seconds');
            $windowEnd = $command->now->modify('+30 seconds');
            $db->insert('backup_runs', [
                'id' => $command->runId, 'request_id' => $command->requestId,
                'root_request_id' => $this->binary($row['root_request_id'] ?? null),
                'attempt' => $this->integer($row['attempt'] ?? null), 'state' => 'awaiting_submission',
                'claim_token' => $command->claimToken, 'claim_fence' => $command->claimFence,
                'submission_provenance' => 'not_submitted', 'started_at' => self::format($command->now),
                'submission_node' => $payload->node, 'submission_vmid' => $payload->vmid,
                'submission_user' => $this->text($row['submission_user'] ?? null),
                'submission_window_start' => self::format($windowStart), 'submission_window_end' => self::format($windowEnd),
            ]);
            $affected = $db->executeStatement("UPDATE backup_requests SET state='starting', run_id=:run, revision=revision+1, updated_at=:now WHERE id=:request AND state='leased' AND claim_token=:token AND claim_fence=:fence AND run_id IS NULL", ['run' => $command->runId, 'now' => self::format($command->now), 'request' => $command->requestId, 'token' => $command->claimToken, 'fence' => $command->claimFence]);
            if (1 !== $affected) throw new RuntimeException('Submission claim changed while locked.');
            $this->event($db, $command, 'awaiting_submission', 'awaiting_submission', $command->now);
            return new SubmissionPreparation(SubmissionPreparationStatus::PreparedNow, submission: new PreparedBackupSubmission(
                $payload, $this->integer($row['attempt'] ?? null), $this->binary($row['guest_id'] ?? null),
                $this->binary($row['target_id'] ?? null), $this->text($row['target_label'] ?? null),
                $this->text($row['guest_name'] ?? null),
            ));
        });
    }

    public function recordAccepted(SubmitClaimedBackupCommand $command, PveUpid $upid): void
    {
        $this->transition($command, function (Connection $db, array $row) use ($command, $upid): void {
            $this->requireAffected($db->executeStatement("UPDATE backup_runs SET state='running', submission_provenance='accepted', upid=:upid, upid_hash=:hash, revision=revision+1 WHERE id=:run AND state='awaiting_submission' AND claim_token=:token AND claim_fence=:fence", ['upid' => $upid->raw, 'hash' => hash('sha256', $upid->raw, true), 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'accepted run');
            $this->requireAffected($db->executeStatement("UPDATE backup_requests SET state='running', submission_provenance='accepted', retry_disposition='controlled_allowed', revision=revision+1, updated_at=:now WHERE id=:request AND state='starting' AND run_id=:run AND claim_token=:token AND claim_fence=:fence", ['now' => self::format($command->now), 'request' => $command->requestId, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'accepted request');
            $this->event($db, $command, 'submission_accepted', 'running', $command->now);
        });
    }

    public function recordDefinitiveRejection(SubmitClaimedBackupCommand $command, PveBackupApiFailureCode $failure, DefinitiveBackupFailureNotice $notice): void
    {
        $this->transition($command, function (Connection $db, array $row) use ($command, $failure, $notice): void {
            $this->requireAffected($db->executeStatement("UPDATE backup_runs SET state='failed', submission_provenance='definitive_rejection', finished_at=:now, status_failure_code=:failure, revision=revision+1 WHERE id=:run AND state='awaiting_submission' AND claim_token=:token AND claim_fence=:fence", ['now' => self::format($command->now), 'failure' => $failure->value, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'rejected run');
            $this->requireAffected($db->executeStatement("UPDATE backup_requests SET state='failed', submission_provenance='definitive_rejection', retry_disposition='controlled_allowed', terminal_code='submission_rejected', terminal_at=:now, claim_token=NULL, lease_owner=NULL, lease_issued_at=NULL, lease_expires_at=NULL, revision=revision+1, updated_at=:now WHERE id=:request AND state='starting' AND run_id=:run AND claim_token=:token AND claim_fence=:fence", ['now' => self::format($command->now), 'request' => $command->requestId, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'rejected request');
            $this->release($db, $command, $command->now);
            $this->createRetry($db, $row, $notice->nextRetryAt, $command->now);
            $this->problems->failure($db, $row, $command->runId, BackupProblemCode::SubmissionRejected, $failure->value, $command->now, $notice->nextRetryAt);
            $this->event($db, $command, 'submission_rejected', 'failed', $command->now, $failure->value);
        });
    }

    public function recordAmbiguous(SubmitClaimedBackupCommand $command, ?PveBackupApiFailureCode $failure): void
    {
        $this->transition($command, function (Connection $db, array $row) use ($command, $failure): void {
            $this->requireAffected($db->executeStatement("UPDATE backup_runs SET state='reconcile_required', submission_provenance='ambiguous', status_failure_code=:failure, revision=revision+1 WHERE id=:run AND state='awaiting_submission' AND claim_token=:token AND claim_fence=:fence", ['failure' => $failure?->value, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'ambiguous run');
            $this->requireAffected($db->executeStatement("UPDATE backup_requests SET state='reconcile_required', submission_provenance='ambiguous', retry_disposition='forbidden_ambiguous', revision=revision+1, updated_at=:now WHERE id=:request AND state='starting' AND run_id=:run AND claim_token=:token AND claim_fence=:fence", ['now' => self::format($command->now), 'request' => $command->requestId, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'ambiguous request');
            $this->problems->attention($db, $row, $command->runId, BackupProblemCode::ReconciliationRequired, 'automatic_retry_forbidden', $command->now);
            $this->event($db, $command, 'submission_ambiguous', 'reconcile_required', $command->now, $failure?->value);
        });
    }

    /** @param callable(Connection, array<string,mixed>): void $operation */
    private function transition(SubmitClaimedBackupCommand $command, callable $operation): void
    {
        $this->connection->transactional(function (Connection $db) use ($command, $operation): void {
            $guestId = $db->fetchOne(
                'SELECT guest_id FROM backup_requests WHERE id = :request',
                ['request' => $command->requestId],
                ['request' => ParameterType::BINARY],
            );
            $this->guestGuard->lock($db, [$this->binary($guestId)]);
            $row = $db->fetchAssociative(<<<'SQL'
SELECT request.*, guest.name AS guest_name, guest.guest_type, guest.vmid,
       node.node_name, target.display_name AS target_label
FROM backup_requests request
JOIN backup_runs run ON run.id=request.run_id AND run.request_id=request.id
JOIN guests guest ON guest.id=request.guest_id
JOIN pve_nodes node ON node.id=request.node_id
JOIN backup_targets target ON target.id=request.target_id
WHERE request.id=:request AND request.run_id=:run
  AND request.state='starting' AND run.state='awaiting_submission'
  AND request.claim_token=:token AND request.claim_fence=:fence
  AND run.claim_token=:token AND run.claim_fence=:fence FOR UPDATE
SQL, ['request' => $command->requestId, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]);
            if (false === $row) throw new RuntimeException('The submission transition lost its fenced authority.');
            $operation($db, $row);
        });
    }

    private function deferBlockedClaim(Connection $db, SubmitClaimedBackupCommand $command, string $blocker): void
    {
        $this->release($db, $command, $command->now);
        $availableAt = $command->now->modify('+120 seconds');
        $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_requests
SET state='retry_wait', available_at=:available, claim_token=NULL, lease_owner=NULL,
    lease_issued_at=NULL, lease_expires_at=NULL, revision=revision+1, updated_at=:now
WHERE id=:request AND state='leased' AND run_id IS NULL
  AND claim_token=:token AND claim_fence=:fence
SQL, [
            'available' => self::format($availableAt), 'now' => self::format($command->now),
            'request' => $command->requestId, 'token' => $command->claimToken,
            'fence' => $command->claimFence,
        ], ['request' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
        $this->requireAffected($affected, 'blocked pre-submit request');
        $sequence = $this->integer($db->fetchOne('SELECT COALESCE(MAX(sequence_no),0)+1 FROM backup_request_events WHERE request_id=:request', ['request' => $command->requestId]));
        $db->insert('backup_request_events', [
            'id' => substr(hash('sha256', "submission-blocked\0".$command->requestId.pack('J', $sequence), true), 0, 16),
            'request_id' => $command->requestId, 'sequence_no' => $sequence,
            'event_type' => 'pre_submit_blocked', 'state' => 'retry_wait',
            'claim_fence' => $command->claimFence, 'occurred_at' => self::format($command->now),
            'detail_code' => $blocker,
        ], ['id' => ParameterType::BINARY, 'request_id' => ParameterType::BINARY]);
    }

    private function cancelBeforeSubmission(Connection $db, SubmitClaimedBackupCommand $command): void
    {
        $this->release($db, $command, $command->now);
        $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_requests
SET state='cancelled', retry_disposition='not_applicable', terminal_code='cancel_requested', terminal_at=:now,
    claim_token=NULL, lease_owner=NULL, lease_issued_at=NULL, lease_expires_at=NULL,
    revision=revision+1, updated_at=:now
WHERE id=:request AND state='leased' AND run_id IS NULL
  AND cancel_requested_at IS NOT NULL AND claim_token=:token AND claim_fence=:fence
SQL, ['now' => self::format($command->now), 'request' => $command->requestId, 'token' => $command->claimToken, 'fence' => $command->claimFence], ['request' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
        $this->requireAffected($affected, 'pre-submit cancellation');
        $sequence = $this->integer($db->fetchOne('SELECT COALESCE(MAX(sequence_no),0)+1 FROM backup_request_events WHERE request_id=:request', ['request' => $command->requestId]));
        $db->insert('backup_request_events', [
            'id' => substr(hash('sha256', "submission-cancelled\0".$command->requestId.pack('J', $sequence), true), 0, 16),
            'request_id' => $command->requestId, 'sequence_no' => $sequence,
            'event_type' => 'cancelled_before_submission', 'state' => 'cancelled',
            'claim_fence' => $command->claimFence, 'occurred_at' => self::format($command->now),
            'detail_code' => 'cancel_requested',
        ], ['id' => ParameterType::BINARY, 'request_id' => ParameterType::BINARY]);
    }

    /** @param array<string,mixed> $row */
    private function createRetry(Connection $db, array $row, DateTimeImmutable $next, DateTimeImmutable $failedAt): void
    {
        $request = $this->binary($row['id'] ?? null);
        $retry = substr(hash('sha256', "retry\0".$request, true), 0, 16);
        $affected = $db->executeStatement(<<<'SQL'
INSERT INTO backup_requests (
 id,root_request_id,attempt,origin,state,reason,priority,scheduled_at,available_at,
 connection_id,cluster_id,guest_id,node_id,placement_revision,placement_observed_at,
 policy_id,policy_revision,target_id,target_revision,shadow_decision_id,resolved_policy_json,
 resolved_policy_hash,expected_size_bytes,retry_disposition,submission_provenance,revision,
 claim_fence,created_at,updated_at
)
SELECT :retry,root_request_id,attempt+1,'retry','retry_wait',reason,priority,:next,:next,
 connection_id,cluster_id,guest_id,node_id,placement_revision,placement_observed_at,
 policy_id,policy_revision,target_id,target_revision,NULL,resolved_policy_json,resolved_policy_hash,
 expected_size_bytes,'not_applicable','not_submitted',1,0,:failed,:failed
FROM backup_requests WHERE id=:request AND state='failed'
SQL, ['retry' => $retry, 'next' => self::format($next), 'failed' => self::format($failedAt), 'request' => $request], ['retry' => ParameterType::BINARY, 'request' => ParameterType::BINARY]);
        if (1 !== $affected) throw new RuntimeException('The definitive submission retry was not created exactly once.');
    }

    /** @param array<string,mixed> $row */
    private function authority(array $row, SubmitClaimedBackupCommand $command): bool
    {
        return 'leased' === ($row['state'] ?? null) && null === ($row['run_id'] ?? null)
            && is_string($row['claim_token'] ?? null) && hash_equals($command->claimToken, $row['claim_token'])
            && $command->claimFence === $this->integer($row['claim_fence'] ?? null)
            && $this->date($row['lease_expires_at'] ?? null) > $command->now;
    }

    /** @param array<string,mixed> $row */
    private function blocker(array $row, DateTimeImmutable $now): ?string
    {
        if (1 !== $this->integer($row['connection_enabled'] ?? null) || 'active' !== ($row['cluster_state'] ?? null)
            || 'active' !== ($row['guest_state'] ?? null) || 0 !== $this->integer($row['is_template'] ?? null)
            || 'online' !== ($row['api_status'] ?? null) || 'active' !== ($row['node_state'] ?? null)
            || 'enabled' !== ($row['policy_status'] ?? null) || 'enabled' !== ($row['target_status'] ?? null)
            || 1 !== $this->integer($row['supports_backup'] ?? null)
            || 0 !== $this->integer($row['storage_disabled'] ?? null)
            || 'active' !== ($row['storage_state'] ?? null) || 1 !== $this->integer($row['storage_enabled'] ?? null)
            || 1 !== $this->integer($row['storage_active'] ?? null) || 1 !== $this->integer($row['executor_authorized'] ?? null)
            || !is_string($row['allowed_node_id'] ?? null)
            || 1 !== $this->integer($row['selection_included'] ?? null)
            || 0 !== $this->integer($row['selection_excluded'] ?? null)
            || 1 !== $this->integer($row['active_request_absent'] ?? null)
            || null === $this->decimal($row['minimum_free_bytes'] ?? null)
            || null === $this->decimal($row['available_bytes'] ?? null)) return 'eligibility_changed';
        if (!hash_equals($this->binary($row['node_id'] ?? null), $this->binary($row['current_node_id'] ?? null))
            || $this->integer($row['placement_revision'] ?? null) !== $this->integer($row['current_placement_revision'] ?? null)
            || $this->integer($row['policy_revision'] ?? null) !== $this->integer($row['current_policy_revision'] ?? null)
            || $this->integer($row['target_revision'] ?? null) !== $this->integer($row['current_target_revision'] ?? null)) return 'snapshot_revision_changed';
        $retentionBlocker = $this->retentionCompatibilityBlocker($row);
        if (null !== $retentionBlocker) return $retentionBlocker;
        foreach (['cluster_seen','guest_seen','placement_seen','node_seen','storage_seen','capacity_seen','executor_seen'] as $field) {
            if (!$this->freshAt($row[$field] ?? null, $now)) return $field.'_stale';
        }
        if ('pbs' === ($row['target_storage_type'] ?? null)
            && (!$this->enabledFlag($row['pbs_connection_enabled'] ?? null)
                || !$this->enabledFlag($row['pbs_writes'] ?? null)
                || 'active' !== ($row['pbs_state'] ?? null)
                || 'datastore_filesystem' !== ($row['pbs_capacity_semantics'] ?? null)
                || !$this->enabledFlag($row['pbs_mapping_valid'] ?? null)
                || null === $this->decimal($row['pbs_available_bytes'] ?? null)
                || !$this->freshAt($row['pbs_capacity_seen'] ?? null, $now)
                || !$this->freshAt($row['pbs_mapping_seen'] ?? null, $now)
                || !$this->freshAt($row['pbs_inventory_seen'] ?? null, $now))) {
            return 'pbs_evidence_invalid';
        }
        return null;
    }

    private function enabledFlag(mixed $value): bool
    {
        return 1 === $value || '1' === $value;
    }

    /** @param array<string,mixed> $row */
    private function retentionCompatibilityBlocker(array $row): ?string
    {
        $pveMajor = $row['current_pve_major'] ?? null;
        if ((!is_int($pveMajor) && !is_string($pveMajor)) || !ctype_digit((string) $pveMajor)
            || (int) $pveMajor < 7 || (int) $pveMajor > 9) {
            return 'pve_evidence_invalid';
        }
        $raw = $row['resolved_policy_json'] ?? null;
        $hash = $row['resolved_policy_hash'] ?? null;
        if (!is_string($raw) || !is_string($hash) || 32 !== strlen($hash)
            || !hash_equals(hash('sha256', $raw, true), $hash)) {
            return 'policy_snapshot_invalid';
        }
        try {
            $policy = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'policy_snapshot_invalid';
        }
        if (!is_array($policy)) return 'policy_snapshot_invalid';
        if (9 === (int) $pveMajor
            && ($this->legacyRetention($policy['desiredRetention'] ?? null)
                || $this->legacyRetention($policy['approvedDeletionRetention'] ?? null))) {
            return 'retention_incompatible';
        }
        return null;
    }

    private function legacyRetention(mixed $retention): bool
    {
        return is_array($retention) && array_key_exists('maxfiles', $retention);
    }

    /** @param array<string,mixed> $row */
    private function lockedResourceBlocker(Connection $db, array $row): ?string
    {
        $node = $db->fetchAssociative('SELECT slot_limit, slots_used FROM backup_node_slots WHERE node_id=:id FOR UPDATE', ['id' => $this->binary($row['node_id'] ?? null)], ['id' => ParameterType::BINARY]);
        $target = $db->fetchAssociative('SELECT slot_limit, slots_used FROM backup_target_slots WHERE target_id=:id FOR UPDATE', ['id' => $this->binary($row['target_id'] ?? null)], ['id' => ParameterType::BINARY]);
        $reservation = $db->fetchAssociative('SELECT target_id,node_id,reserved_bytes,released_at FROM backup_capacity_reservations WHERE request_id=:id FOR UPDATE', ['id' => $this->binary($row['id'] ?? null)], ['id' => ParameterType::BINARY]);
        if (false === $node || false === $target || false === $reservation
            || $this->integer($node['slots_used'] ?? null) < 1
            || $this->integer($node['slots_used'] ?? null) > $this->integer($node['slot_limit'] ?? null)
            || $this->integer($target['slots_used'] ?? null) < 1
            || $this->integer($target['slots_used'] ?? null) > $this->integer($target['slot_limit'] ?? null)
            || $this->integer($target['slot_limit'] ?? null) !== $this->integer($row['fixed_parallel_limit'] ?? null)
            || null !== ($reservation['released_at'] ?? null)
            || !is_string($reservation['target_id'] ?? null) || !hash_equals($this->binary($row['target_id'] ?? null), $reservation['target_id'])
            || !is_string($reservation['node_id'] ?? null) || !hash_equals($this->binary($row['node_id'] ?? null), $reservation['node_id'])
            || $this->decimal($reservation['reserved_bytes'] ?? null) !== $this->decimal($row['expected_size_bytes'] ?? null)) {
            return 'claim_resources_inconsistent';
        }
        $reserved = $this->decimal($db->fetchOne('SELECT COALESCE(SUM(reserved_bytes),0) FROM backup_capacity_reservations WHERE target_id=:target AND released_at IS NULL', ['target' => $this->binary($row['target_id'] ?? null)]));
        $available = $this->decimal($row['available_bytes'] ?? null);
        if ('pbs' === ($row['target_storage_type'] ?? null)) {
            $pbs = $this->decimal($row['pbs_available_bytes'] ?? null);
            if (null === $pbs) return 'pbs_evidence_invalid';
            if (null === $available || 1 === $this->integer($db->fetchOne('SELECT CAST(:pbs AS DECIMAL(65,0)) < CAST(:pve AS DECIMAL(65,0))', ['pbs' => $pbs, 'pve' => $available]))) {
                $available = $pbs;
            }
        }
        $minimum = $this->decimal($row['minimum_free_bytes'] ?? null);
        if (null === $reserved || null === $available || null === $minimum
            || 1 !== $this->integer($db->fetchOne('SELECT CAST(:available AS DECIMAL(65,0)) >= CAST(:reserved AS DECIMAL(65,0)) + CAST(:minimum AS DECIMAL(65,0))', ['available' => $available, 'reserved' => $reserved, 'minimum' => $minimum]))) {
            return 'capacity_unavailable';
        }
        return null;
    }

    private function freshAt(mixed $value, DateTimeImmutable $now): bool
    {
        if (!is_string($value)) return false;
        try { $seen = $this->date($value); } catch (RuntimeException) { return false; }
        return $seen <= $now && $now <= $seen->add(new DateInterval('PT'.$this->freshnessSeconds.'S'));
    }

    /** @param array<string,mixed> $row */
    private function payload(array $row): PveBackupSubmission
    {
        $raw = $this->text($row['resolved_policy_json'] ?? null);
        if (!hash_equals(hash('sha256', $raw, true), $this->binaryLength($row['resolved_policy_hash'] ?? null, 32))) throw new RuntimeException('Policy snapshot hash mismatch.');
        try { $policy = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new RuntimeException('Invalid policy snapshot.', 0, $e); }
        if (!is_array($policy)) throw new RuntimeException('Invalid policy snapshot.');
        // Retention parameters are deletion-capable PVE controls. Desired
        // retention remains in the immutable policy snapshot for planning and
        // display, but only an explicit deletion approval may reach vzdump.
        // A PVE PBS storage delegates pruning to PBS. Revalidate the current
        // storage type here so a missing/corrupt PBS mapping or stale legacy
        // snapshot can never turn into deletion-capable vzdump parameters.
        $retention = 'pbs' !== ($row['target_storage_type'] ?? null)
            ? ($policy['approvedDeletionRetention'] ?? null)
            : null;
        $legacy = null; $prune = null;
        if (is_array($retention) && isset($retention['maxfiles']) && is_int($retention['maxfiles'])) $legacy = $retention['maxfiles'];
        elseif (is_array($retention) && is_array($retention['prune-backups'] ?? null)) {
            $values = $retention['prune-backups'];
            $prune = new PvePruneBackups(
                $this->nullableBoolean($values, 'keep-all'),
                $this->nullableInteger($values, 'keep-last'),
                $this->nullableInteger($values, 'keep-hourly'),
                $this->nullableInteger($values, 'keep-daily'),
                $this->nullableInteger($values, 'keep-weekly'),
                $this->nullableInteger($values, 'keep-monthly'),
                $this->nullableInteger($values, 'keep-yearly'),
            );
        }
        $recipients = null;
        if (is_array($policy['failureNotificationRecipients'] ?? null) && [] !== $policy['failureNotificationRecipients']) {
            $addresses = array_values($policy['failureNotificationRecipients']);
            if (array_filter($addresses, static fn (mixed $address): bool => !is_string($address)) !== []) {
                throw new RuntimeException('Invalid failure notification recipients.');
            }
            /** @var list<string> $addresses */
            $recipients = new PveBackupFailureRecipients($addresses);
        }
        return new PveBackupSubmission($this->text($row['node_name'] ?? null), $this->integer($row['vmid'] ?? null), PveGuestType::from($this->text($row['guest_type'] ?? null)), $this->text($row['storage_name'] ?? null), PveBackupMode::from($this->text($policy['mode'] ?? null)), PveBackupCompression::from($this->text($policy['compression'] ?? null)), $prune, $legacy, $recipients);
    }

    private function release(Connection $db, SubmitClaimedBackupCommand $command, DateTimeImmutable $at): void
    {
        $now = self::format($at);
        $node = $db->executeStatement('UPDATE backup_node_slots slots JOIN backup_requests request ON request.node_id=slots.node_id SET slots.slots_used=slots.slots_used-1, slots.revision=slots.revision+1, slots.updated_at=:now WHERE request.id=:request AND slots.slots_used>0', ['now' => $now, 'request' => $command->requestId]);
        $target = $db->executeStatement('UPDATE backup_target_slots slots JOIN backup_requests request ON request.target_id=slots.target_id SET slots.slots_used=slots.slots_used-1, slots.revision=slots.revision+1, slots.updated_at=:now WHERE request.id=:request AND slots.slots_used>0', ['now' => $now, 'request' => $command->requestId]);
        $reservation = $db->executeStatement('UPDATE backup_capacity_reservations SET released_at=:now WHERE request_id=:request AND released_at IS NULL', ['now' => $now, 'request' => $command->requestId]);
        if (1 !== $node || 1 !== $target || 1 !== $reservation) throw new RuntimeException('Submission terminal resource release is inconsistent.');
    }

    private function event(Connection $db, SubmitClaimedBackupCommand $command, string $type, string $state, DateTimeImmutable $at, ?string $detail = null): void
    {
        $sequence = $this->integer($db->fetchOne('SELECT COALESCE(MAX(sequence_no),0)+1 FROM backup_run_events WHERE run_id=:run', ['run' => $command->runId]));
        $db->insert('backup_run_events', ['id' => substr(hash('sha256', "submission\0".$command->runId.pack('J', $sequence), true),0,16), 'run_id' => $command->runId, 'sequence_no' => $sequence, 'event_type' => $type, 'state' => $state, 'occurred_at' => self::format($at), 'detail_code' => $detail]);
    }

    private function integer(mixed $v): int { if (is_int($v) && $v>=0) return $v; if (is_string($v)&&ctype_digit($v)&&strlen($v)<19) return (int)$v; throw new RuntimeException('Invalid submission integer.'); }
    private function requireAffected(int|string $affected, string $transition): void { if (1 !== (int) $affected) throw new RuntimeException('The '.$transition.' transition lost its fence.'); }
    private function decimal(mixed $v): ?string { if (is_int($v) && $v >= 0) return (string) $v; if (!is_string($v) || 1 !== preg_match('/^(0|[1-9][0-9]{0,64})$/D', $v)) return null; return $v; }
    /** @param array<mixed> $values */
    private function nullableInteger(array $values, string $key): ?int { $value = $values[$key] ?? null; if (null === $value || (is_int($value) && $value >= 0)) return $value; throw new RuntimeException('Invalid prune retention integer.'); }
    /** @param array<mixed> $values */
    private function nullableBoolean(array $values, string $key): ?bool { $value = $values[$key] ?? null; if (null === $value || is_bool($value)) return $value; throw new RuntimeException('Invalid prune retention boolean.'); }
    private function text(mixed $v): string { if (!is_string($v)||''===$v) throw new RuntimeException('Invalid submission text.'); return $v; }
    private function binary(mixed $v): string { return $this->binaryLength($v,16); }
    private function binaryLength(mixed $v,int $length): string { if (!is_string($v)||$length!==strlen($v)) throw new RuntimeException('Invalid submission identifier.'); return $v; }
    private function date(mixed $v): DateTimeImmutable { if (!is_string($v)) throw new RuntimeException('Invalid submission timestamp.'); $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$v,new DateTimeZone('UTC')); if(false===$d) throw new RuntimeException('Invalid submission timestamp.'); return $d; }
    private static function format(DateTimeImmutable $v): string { return $v->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
}
