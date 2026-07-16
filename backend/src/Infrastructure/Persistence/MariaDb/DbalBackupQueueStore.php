<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Queue\BackupQueueStore;
use App\Application\Backup\Queue\ClaimedBackupRequest;
use App\Application\Backup\Queue\ClaimNextBackupCommand;
use App\Application\Backup\Queue\ExpectedBackupSize;
use App\Application\Backup\Queue\FinalizeClaimedBackupCommand;
use App\Application\Backup\Queue\QueueClaimTokenSource;
use App\Application\Backup\Queue\ShadowPromotion;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use App\Domain\Shared\UInt64Decimal;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalBackupQueueStore implements BackupQueueStore
{
    private DbalBackupRequestGuestGuard $guestGuard;

    public function __construct(
        private Connection $connection,
        private QueueClaimTokenSource $tokens,
        private ExpectedBackupSize $sizes,
        private EvidenceFreshnessPolicy $freshness,
        private int $deferSeconds = 120,
    ) {
        if ($deferSeconds < 1 || $deferSeconds > 86400) {
            throw new \InvalidArgumentException('The queue defer interval must be between 1 and 86400 seconds.');
        }
        $this->guestGuard = new DbalBackupRequestGuestGuard();
    }

    public function promote(ShadowPromotion $promotion): string
    {
        return $this->connection->transactional(function (Connection $db) use ($promotion): string {
            $guestId = $db->fetchOne(
                'SELECT guest_id FROM scheduler_decisions WHERE id = :id',
                ['id' => $promotion->shadowDecisionId],
                ['id' => ParameterType::BINARY],
            );
            $this->guestGuard->lock($db, [$this->binary($guestId)]);
            $row = $db->fetchAssociative(<<<'SQL'
SELECT decision.*, guest.provisioned_size_bytes, state.last_success_size_bytes
FROM scheduler_decisions decision
JOIN guests guest
  ON guest.connection_id = decision.connection_id
 AND guest.cluster_id = decision.cluster_id
 AND guest.id = decision.guest_id
LEFT JOIN guest_backup_state state
  ON state.guest_id = decision.guest_id
 AND state.policy_id = decision.policy_id
 AND state.target_id = decision.target_id
WHERE decision.id = :id
FOR UPDATE
SQL, ['id' => $promotion->shadowDecisionId], ['id' => ParameterType::BINARY]);
            if (false === $row
                || 'eligible' !== ($row['outcome'] ?? null)
                || !is_string($row['policy_snapshot_hash'] ?? null)
                || !hash_equals($promotion->resolvedPolicyHash, $row['policy_snapshot_hash'])
            ) {
                throw new RuntimeException('Only a matching eligible shadow winner can be promoted.');
            }

            $existing = $this->existingRequestId($db, $row, $promotion->scheduledAt);
            if (null !== $existing) {
                return $existing;
            }
            if (null !== $this->guestGuard->activeRequestId($db, $this->binary($row['guest_id'] ?? null))) {
                throw new RuntimeException('The guest already has an active backup request.');
            }

            $expected = $this->sizes->calculate(
                $this->decimal($row['last_success_size_bytes'] ?? null),
                $this->decimal($row['provisioned_size_bytes'] ?? null),
            );
            if (null === $expected) {
                throw new RuntimeException('Expected backup size evidence is missing.');
            }

            $scheduledAt = $this->format($promotion->scheduledAt);
            $db->insert('backup_requests', [
                'id' => $promotion->requestId,
                'root_request_id' => $promotion->requestId,
                'attempt' => 1,
                'origin' => 'automatic',
                'state' => 'pending',
                'reason' => $this->text($row['reason'] ?? null),
                'priority' => $this->integer($row['priority'] ?? null),
                'scheduled_at' => $scheduledAt,
                'available_at' => $scheduledAt,
                'connection_id' => $this->binary($row['connection_id'] ?? null),
                'cluster_id' => $this->binary($row['cluster_id'] ?? null),
                'guest_id' => $this->binary($row['guest_id'] ?? null),
                'node_id' => $this->binary($row['node_id'] ?? null),
                'placement_revision' => $this->integer($row['placement_revision'] ?? null),
                'placement_observed_at' => $this->text($row['placement_observed_at'] ?? null),
                'policy_id' => $this->binary($row['policy_id'] ?? null),
                'policy_revision' => $this->integer($row['policy_revision'] ?? null),
                'target_id' => $this->binary($row['target_id'] ?? null),
                'target_revision' => $this->integer($row['target_revision'] ?? null),
                'shadow_decision_id' => $promotion->shadowDecisionId,
                'resolved_policy_json' => $promotion->resolvedPolicyJson,
                'resolved_policy_hash' => $promotion->resolvedPolicyHash,
                'expected_size_bytes' => $expected->value,
                'retry_disposition' => 'not_applicable',
                'submission_provenance' => 'not_submitted',
                'revision' => 1,
                'claim_fence' => 0,
                'created_at' => $scheduledAt,
                'updated_at' => $scheduledAt,
            ]);
            $this->appendEvent($db, $promotion->requestId, 'promoted', 'pending', $promotion->scheduledAt);

            return $promotion->requestId;
        });
    }

    public function claim(ClaimNextBackupCommand $command): ?ClaimedBackupRequest
    {
        return $this->connection->transactional(function (Connection $db) use ($command): ?ClaimedBackupRequest {
            $row = $db->fetchAssociative(<<<'SQL'
SELECT request.*, target.storage_id, target.minimum_free_bytes, target.fixed_parallel_limit
FROM backup_requests request
JOIN backup_targets target
  ON target.connection_id = request.connection_id
 AND target.cluster_id = request.cluster_id
 AND target.id = request.target_id
WHERE (:allow_new = 1 AND request.state IN ('pending', 'retry_wait') AND request.available_at <= :now
       AND request.cancel_requested_at IS NULL)
   OR (request.state IN ('leased', 'starting', 'running', 'reconcile_required')
       AND request.lease_owner = :owner AND request.lease_expires_at > :now)
   OR (request.state IN ('leased', 'starting', 'running', 'reconcile_required') AND request.lease_expires_at <= :now)
ORDER BY
  CASE
    WHEN request.state IN ('leased', 'starting', 'running', 'reconcile_required')
      AND request.lease_owner = :owner AND request.lease_expires_at > :now THEN 0
    WHEN request.state IN ('leased', 'starting', 'running', 'reconcile_required') THEN 1
    ELSE 2
  END,
  request.priority DESC, request.scheduled_at, request.id
LIMIT 1
FOR UPDATE SKIP LOCKED
SQL, ['now' => $this->format($command->now), 'owner' => $command->workerId, 'allow_new' => $command->allowNewClaims ? 1 : 0], ['owner' => ParameterType::BINARY]);
            if (false === $row) {
                return null;
            }

            if (in_array($row['state'] ?? null, ['leased', 'starting', 'running', 'reconcile_required'], true)) {
                if (is_string($row['lease_owner'] ?? null)
                    && hash_equals($command->workerId, $row['lease_owner'])
                    && $this->date($row['lease_expires_at'] ?? null)?->getTimestamp() > $command->now->getTimestamp()) {
                    return $this->existingClaim($row);
                }
                return $this->takeover($db, $row, $command);
            }

            $evidence = $this->lockRevalidationEvidence($db, $row);
            $blocker = $this->revalidationBlocker($row, $evidence, $command->now);
            if (null !== $blocker) {
                $this->defer($db, $row, $command->now, $blocker);

                return null;
            }
            if (false === $evidence) {
                throw new RuntimeException('Revalidation evidence vanished after its blocker check.');
            }

            $expected = $this->sizes->calculate(
                $this->decimal($evidence['last_success_size_bytes'] ?? null),
                $this->decimal($evidence['provisioned_size_bytes'] ?? null),
            );
            if (null === $expected) {
                $this->defer($db, $row, $command->now, 'expected_size_missing');

                return null;
            }
            $row['expected_size_bytes'] = $expected->value;
            $row['available_bytes'] = $this->effectiveAvailableBytes($evidence);
            $row['capacity_observed_at'] = $this->effectiveCapacityObservedAt($evidence);

            $slotBlocker = $this->lockAndCheckSlots($db, $row, $command->now);
            if (null !== $slotBlocker) {
                $this->defer($db, $row, $command->now, $slotBlocker);

                return null;
            }
            if (!$this->capacityAvailable($db, $row)) {
                $this->defer($db, $row, $command->now, 'capacity_unavailable');

                return null;
            }

            $now = $this->format($command->now);
            $nodeAffected = $db->executeStatement(
                'UPDATE backup_node_slots SET slots_used = slots_used + 1, revision = revision + 1, updated_at = :now WHERE node_id = :id',
                ['now' => $now, 'id' => $row['node_id']],
            );
            $targetAffected = $db->executeStatement(
                'UPDATE backup_target_slots SET slots_used = slots_used + 1, revision = revision + 1, updated_at = :now WHERE target_id = :id',
                ['now' => $now, 'id' => $row['target_id']],
            );
            if (1 !== (int) $nodeAffected || 1 !== (int) $targetAffected) {
                throw new RuntimeException('The locked backup slot reservation changed unexpectedly.');
            }
            $db->insert('backup_capacity_reservations', [
                'request_id' => $row['id'],
                'connection_id' => $row['connection_id'],
                'cluster_id' => $row['cluster_id'],
                'target_id' => $row['target_id'],
                'node_id' => $row['node_id'],
                'reserved_bytes' => $expected->value,
                'capacity_observed_at' => $row['capacity_observed_at'],
                'created_at' => $now,
            ]);

            return $this->persistClaim($db, $row, $command, 'leased', 'claimed');
        });
    }

    public function finalize(FinalizeClaimedBackupCommand $command): bool
    {
        return $this->connection->transactional(function (Connection $db) use ($command): bool {
            $row = $db->fetchAssociative(
                'SELECT * FROM backup_requests WHERE id = :id FOR UPDATE',
                ['id' => $command->requestId],
                ['id' => ParameterType::BINARY],
            );
            if (false === $row
                || !in_array($row['state'] ?? null, ['leased', 'starting', 'running', 'reconcile_required'], true)
                || !is_string($row['claim_token'] ?? null)
                || !hash_equals($command->claimToken, $row['claim_token'])
                || $command->claimFence !== $this->integer($row['claim_fence'] ?? null)
            ) {
                return false;
            }

            $node = $db->fetchAssociative(
                'SELECT slots_used FROM backup_node_slots WHERE node_id = :id FOR UPDATE',
                ['id' => $row['node_id']],
            );
            $target = $db->fetchAssociative(
                'SELECT slots_used FROM backup_target_slots WHERE target_id = :id FOR UPDATE',
                ['id' => $row['target_id']],
            );
            $reservation = $db->fetchAssociative(
                'SELECT released_at FROM backup_capacity_reservations WHERE request_id = :id FOR UPDATE',
                ['id' => $command->requestId],
            );
            if (false === $node || false === $target || false === $reservation
                || $this->integer($node['slots_used'] ?? null) < 1
                || $this->integer($target['slots_used'] ?? null) < 1
                || null !== ($reservation['released_at'] ?? null)
            ) {
                throw new RuntimeException('Claimed backup resources are inconsistent.');
            }

            $now = $this->format($command->now);
            $nodeAffected = $db->executeStatement(
                'UPDATE backup_node_slots SET slots_used = slots_used - 1, revision = revision + 1, updated_at = :now WHERE node_id = :id AND slots_used > 0',
                ['now' => $now, 'id' => $row['node_id']],
            );
            $targetAffected = $db->executeStatement(
                'UPDATE backup_target_slots SET slots_used = slots_used - 1, revision = revision + 1, updated_at = :now WHERE target_id = :id AND slots_used > 0',
                ['now' => $now, 'id' => $row['target_id']],
            );
            $reservationAffected = $db->executeStatement(
                'UPDATE backup_capacity_reservations SET released_at = :now WHERE request_id = :id AND released_at IS NULL',
                ['now' => $now, 'id' => $command->requestId],
            );
            if (1 !== $nodeAffected || 1 !== $targetAffected || 1 !== $reservationAffected) {
                throw new RuntimeException('The terminal queue resources changed while locked.');
            }
            $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_requests
SET state = :state, claim_token = NULL, lease_owner = NULL, lease_issued_at = NULL, lease_expires_at = NULL,
    terminal_code = :terminal_code, terminal_at = :now, revision = revision + 1, updated_at = :now
WHERE id = :id AND claim_token = :token AND claim_fence = :fence
SQL, [
                'state' => $command->terminalState,
                'terminal_code' => $command->detailCode,
                'now' => $now,
                'id' => $command->requestId,
                'token' => $command->claimToken,
                'fence' => $command->claimFence,
            ]);
            if (1 !== $affected) {
                throw new RuntimeException('The terminal queue fence changed while locked.');
            }
            $this->appendEvent(
                $db,
                $command->requestId,
                'finalized',
                $command->terminalState,
                $command->now,
                $command->claimFence,
                $command->detailCode,
            );

            return true;
        });
    }

    /** @param array<string, mixed> $row */
    private function existingRequestId(Connection $db, array $row, DateTimeImmutable $scheduledAt): ?string
    {
        $existing = $db->fetchOne(<<<'SQL'
SELECT id FROM backup_requests
WHERE policy_id = :policy AND guest_id = :guest AND scheduled_at = :scheduled
SQL, [
            'policy' => $row['policy_id'],
            'guest' => $row['guest_id'],
            'scheduled' => $this->format($scheduledAt),
        ]);

        return false === $existing ? null : $this->binary($existing);
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>|false
     */
    private function lockRevalidationEvidence(Connection $db, array $row): array|false
    {
        return $db->fetchAssociative(<<<'SQL'
SELECT connection.enabled AS connection_enabled,
       cluster.inventory_state AS cluster_state, cluster.last_seen_at AS cluster_observed_at,
       guest.inventory_state AS guest_state, guest.last_seen_at AS guest_observed_at,
       guest.is_template,
       guest.provisioned_size_bytes,
       placement.node_id AS current_node_id,
       placement.placement_revision AS current_placement_revision,
       placement.observed_at AS current_placement_observed_at,
       node.api_status AS node_api_status, node.last_seen_at AS node_observed_at,
       node.inventory_state AS node_state,
       policy.revision AS current_policy_revision,
       policy.status AS policy_status,
       target.revision AS current_target_revision,
       target.status AS target_status,
       target.minimum_free_bytes,
       target.fixed_parallel_limit,
       target.pbs_connection_id,
       storage.storage_type AS target_storage_type,
       allowed.node_id AS allowed_node_id,
       storage.supports_backup, storage.last_seen_at AS storage_observed_at,
       storage.disabled AS storage_disabled,
       storage.inventory_state AS storage_state,
       node_storage.enabled AS node_storage_enabled,
       node_storage.active AS node_storage_active,
       node_storage.available_bytes,
       node_storage.observed_at AS capacity_observed_at,
       evidence.authorized AS executor_authorized,
       evidence.observed_at AS executor_observed_at,
       pbs_datastore.allows_backup_writes AS pbs_writes,
       pbs_datastore.inventory_state AS pbs_state,
       pbs_capacity.semantics AS pbs_capacity_semantics,
       pbs_capacity.available_bytes AS pbs_available_bytes,
       pbs_capacity.observed_at AS pbs_capacity_observed_at,
       mapping.observed_at AS pbs_mapping_observed_at,
       pbs_datastore.last_seen_at AS pbs_inventory_observed_at,
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
           AND (
             active_request.state IN ('leased', 'starting', 'running', 'reconcile_required')
             OR (active_request.state IN ('pending', 'retry_wait') AND (
               active_request.priority > request.priority
               OR (active_request.priority = request.priority AND active_request.scheduled_at < request.scheduled_at)
               OR (active_request.priority = request.priority AND active_request.scheduled_at = request.scheduled_at
                 AND active_request.id < request.id)
             ))
           )) AS active_request_absent,
       state.last_success_size_bytes
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
LEFT JOIN pve_node_storage_state node_storage ON node_storage.node_id = placement.node_id AND node_storage.storage_id = target.storage_id
LEFT JOIN current_executor_permission_evidence evidence
 ON evidence.connection_id = request.connection_id AND evidence.cluster_id = request.cluster_id
 AND evidence.target_id = request.target_id AND evidence.guest_id = request.guest_id
 AND evidence.node_id = placement.node_id AND evidence.storage_id = target.storage_id
LEFT JOIN pbs_datastores pbs_datastore ON pbs_datastore.connection_id = target.pbs_connection_id AND pbs_datastore.id = target.pbs_datastore_id
LEFT JOIN pbs_datastore_capacity_state pbs_capacity ON pbs_capacity.datastore_id = target.pbs_datastore_id
LEFT JOIN proxmox_connections pbs_connection ON pbs_connection.id = target.pbs_connection_id
LEFT JOIN pve_storage_pbs_mappings mapping ON mapping.storage_id = target.storage_id
LEFT JOIN pbs_namespaces pbs_namespace ON pbs_namespace.datastore_id = target.pbs_datastore_id AND pbs_namespace.id = target.pbs_namespace_id
LEFT JOIN guest_backup_state state ON state.guest_id = request.guest_id AND state.policy_id = request.policy_id AND state.target_id = request.target_id
WHERE request.id = :id
FOR UPDATE
SQL, ['id' => $row['id']]);
    }

    /** @param array<string, mixed> $request
     *  @param array<string, mixed>|false $evidence
     */
    private function revalidationBlocker(array $request, array|false $evidence, DateTimeImmutable $now): ?string
    {
        if (false === $evidence) {
            return 'revalidation_evidence_missing';
        }
        if (1 !== $this->nullableInteger($evidence['connection_enabled'] ?? null)
            || 'active' !== ($evidence['cluster_state'] ?? null)
            || 'active' !== ($evidence['guest_state'] ?? null)
            || 0 !== $this->nullableInteger($evidence['is_template'] ?? null)
            || 'online' !== ($evidence['node_api_status'] ?? null)
            || 'active' !== ($evidence['node_state'] ?? null)
            || 'enabled' !== ($evidence['policy_status'] ?? null)
            || 'enabled' !== ($evidence['target_status'] ?? null)
            || 1 !== $this->nullableInteger($evidence['supports_backup'] ?? null)
            || 0 !== $this->nullableInteger($evidence['storage_disabled'] ?? null)
            || 'active' !== ($evidence['storage_state'] ?? null)
            || 1 !== $this->nullableInteger($evidence['node_storage_enabled'] ?? null)
            || 1 !== $this->nullableInteger($evidence['node_storage_active'] ?? null)
            || 1 !== $this->nullableInteger($evidence['executor_authorized'] ?? null)
            || !is_string($evidence['allowed_node_id'] ?? null)
            || 1 !== $this->nullableInteger($evidence['selection_included'] ?? null)
            || 0 !== $this->nullableInteger($evidence['selection_excluded'] ?? null)
            || 1 !== $this->nullableInteger($evidence['active_request_absent'] ?? null)
            || null === $this->decimal($evidence['minimum_free_bytes'] ?? null)
            || null === $this->decimal($evidence['available_bytes'] ?? null)
        ) {
            return 'eligibility_changed';
        }
        if (!hash_equals($this->binary($request['node_id'] ?? null), $this->binary($evidence['current_node_id'] ?? null))
            || $this->integer($request['placement_revision'] ?? null) !== $this->integer($evidence['current_placement_revision'] ?? null)
            || $this->integer($request['policy_revision'] ?? null) !== $this->integer($evidence['current_policy_revision'] ?? null)
            || $this->integer($request['target_revision'] ?? null) !== $this->integer($evidence['current_target_revision'] ?? null)
        ) {
            return 'snapshot_revision_changed';
        }
        foreach ([
            'cluster_observed_at', 'guest_observed_at', 'node_observed_at', 'storage_observed_at',
            'current_placement_observed_at', 'capacity_observed_at', 'executor_observed_at',
        ] as $field) {
            if (!$this->freshAt($evidence[$field] ?? null, $now)) {
                return $field.'_stale';
            }
        }
        if ('pbs' === ($evidence['target_storage_type'] ?? null)) {
            if (1 !== $this->nullableInteger($evidence['pbs_connection_enabled'] ?? null)
                || 1 !== $this->nullableInteger($evidence['pbs_writes'] ?? null)
                || 'active' !== ($evidence['pbs_state'] ?? null)
                || 'datastore_filesystem' !== ($evidence['pbs_capacity_semantics'] ?? null)
                || 1 !== $this->nullableInteger($evidence['pbs_mapping_valid'] ?? null)
                || null === $this->decimal($evidence['pbs_available_bytes'] ?? null)
                || !$this->freshAt($evidence['pbs_capacity_observed_at'] ?? null, $now)
                || !$this->freshAt($evidence['pbs_mapping_observed_at'] ?? null, $now)
                || !$this->freshAt($evidence['pbs_inventory_observed_at'] ?? null, $now)
            ) {
                return 'pbs_evidence_invalid';
            }
        }

        return null;
    }

    private function freshAt(mixed $value, DateTimeImmutable $now): bool
    {
        $observedAt = $this->date($value);
        if (null === $observedAt || $observedAt > $now) {
            return false;
        }

        return $now <= $observedAt->add(new DateInterval('PT'.$this->freshness->maximumAgeSeconds.'S'));
    }

    /** @param array<string, mixed> $evidence */
    private function effectiveAvailableBytes(array $evidence): string
    {
        $pve = $this->decimal($evidence['available_bytes'] ?? null)
            ?? throw new RuntimeException('PVE capacity evidence is missing.');
        if ('pbs' !== ($evidence['target_storage_type'] ?? null)) {
            return $pve;
        }
        $pbs = $this->decimal($evidence['pbs_available_bytes'] ?? null)
            ?? throw new RuntimeException('PBS capacity evidence is missing.');

        return $this->decimalLessThan($pve, $pbs) ? $pve : $pbs;
    }

    /** @param array<string, mixed> $evidence */
    private function effectiveCapacityObservedAt(array $evidence): string
    {
        $pve = $this->date($evidence['capacity_observed_at'] ?? null)
            ?? throw new RuntimeException('PVE capacity timestamp is missing.');
        if ('pbs' !== ($evidence['target_storage_type'] ?? null)) {
            return $this->format($pve);
        }
        $pbs = $this->date($evidence['pbs_capacity_observed_at'] ?? null)
            ?? throw new RuntimeException('PBS capacity timestamp is missing.');

        return $this->format($pve < $pbs ? $pve : $pbs);
    }

    /** @param array<string, mixed> $row */
    private function lockAndCheckSlots(Connection $db, array $row, DateTimeImmutable $now): ?string
    {
        $parallelLimit = $this->nullableInteger($row['fixed_parallel_limit'] ?? null);
        if (null === $parallelLimit || $parallelLimit < 1) {
            return 'target_parallel_limit_missing';
        }
        $formatted = $this->format($now);
        $db->executeStatement(<<<'SQL'
INSERT IGNORE INTO backup_node_slots
    (node_id, connection_id, cluster_id, slot_limit, slots_used, revision, updated_at)
VALUES (:node, :connection, :cluster, 1, 0, 1, :now)
SQL, ['node' => $row['node_id'], 'connection' => $row['connection_id'], 'cluster' => $row['cluster_id'], 'now' => $formatted]);
        $db->executeStatement(<<<'SQL'
INSERT IGNORE INTO backup_target_slots
    (target_id, connection_id, cluster_id, slot_limit, slots_used, revision, updated_at)
VALUES (:target, :connection, :cluster, :limit, 0, 1, :now)
SQL, [
            'target' => $row['target_id'],
            'connection' => $row['connection_id'],
            'cluster' => $row['cluster_id'],
            'limit' => $parallelLimit,
            'now' => $formatted,
        ]);
        $node = $db->fetchAssociative(
            'SELECT slot_limit, slots_used FROM backup_node_slots WHERE node_id = :id FOR UPDATE',
            ['id' => $row['node_id']],
        );
        $target = $db->fetchAssociative(
            'SELECT slot_limit, slots_used FROM backup_target_slots WHERE target_id = :id FOR UPDATE',
            ['id' => $row['target_id']],
        );
        if (false === $node || $this->integer($node['slots_used'] ?? null) >= $this->integer($node['slot_limit'] ?? null)) {
            return 'node_slot_unavailable';
        }
        if (false === $target || $this->integer($target['slots_used'] ?? null) >= $this->integer($target['slot_limit'] ?? null)) {
            return 'target_slot_unavailable';
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function capacityAvailable(Connection $db, array $row): bool
    {
        $reserved = $db->fetchOne(<<<'SQL'
SELECT COALESCE(SUM(reserved_bytes), 0)
FROM backup_capacity_reservations
WHERE target_id = :target AND released_at IS NULL
SQL, ['target' => $row['target_id']]);

        return 1 === $this->integer($db->fetchOne(<<<'SQL'
SELECT CAST(:available AS DECIMAL(65, 0)) >=
       CAST(:reserved AS DECIMAL(65, 0)) + CAST(:expected AS DECIMAL(65, 0)) + CAST(:minimum AS DECIMAL(65, 0))
SQL, [
            'available' => $this->decimal($row['available_bytes'] ?? null),
            'reserved' => $this->decimal($reserved),
            'expected' => $this->decimal($row['expected_size_bytes'] ?? null),
            'minimum' => $this->decimal($row['minimum_free_bytes'] ?? null),
        ]));
    }

    /** @param array<string, mixed> $row */
    private function takeover(Connection $db, array $row, ClaimNextBackupCommand $command): ClaimedBackupRequest
    {
        $reservation = $db->fetchAssociative(
            'SELECT released_at FROM backup_capacity_reservations WHERE request_id = :id FOR UPDATE',
            ['id' => $row['id']],
        );
        if (false === $reservation || null !== ($reservation['released_at'] ?? null)) {
            throw new RuntimeException('An expired active request has no active capacity reservation.');
        }

        return $this->persistClaim($db, $row, $command, $this->text($row['state'] ?? null), 'taken_over');
    }

    /** @param array<string,mixed> $row */
    private function existingClaim(array $row): ClaimedBackupRequest
    {
        return new ClaimedBackupRequest(
            $this->binary($row['id'] ?? null),
            $this->binary($row['claim_token'] ?? null),
            $this->integer($row['claim_fence'] ?? null),
            $this->date($row['lease_expires_at'] ?? null)
                ?? throw new RuntimeException('An owned backup lease has no expiry.'),
            $this->binary($row['node_id'] ?? null),
            $this->binary($row['target_id'] ?? null),
            $this->decimal($row['expected_size_bytes'] ?? null)
                ?? throw new RuntimeException('An owned backup lease has no expected size.'),
            $this->text($row['state'] ?? null),
            $this->nullableBinary($row['run_id'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private function persistClaim(
        Connection $db,
        array $row,
        ClaimNextBackupCommand $command,
        string $state,
        string $eventType,
    ): ClaimedBackupRequest {
        $token = $this->tokens->next();
        if (16 !== strlen($token)) {
            throw new RuntimeException('The queue claim token source returned an invalid token.');
        }
        $previousFence = $this->integer($row['claim_fence'] ?? null);
        if (PHP_INT_MAX === $previousFence) {
            throw new RuntimeException('The queue claim fence exhausted the platform integer range.');
        }
        $fence = $previousFence + 1;
        $expiry = $command->now->add(new DateInterval('PT'.$command->leaseSeconds.'S'));
        $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_requests
SET state = :state, expected_size_bytes = :expected, claim_token = :token, claim_fence = :fence,
    lease_owner = :owner, lease_issued_at = :now, lease_expires_at = :expiry,
    revision = revision + 1, updated_at = :now
WHERE id = :id AND claim_fence = :previous_fence
SQL, [
            'state' => $state,
            'expected' => $row['expected_size_bytes'],
            'token' => $token,
            'fence' => $fence,
            'owner' => $command->workerId,
            'now' => $this->format($command->now),
            'expiry' => $this->format($expiry),
            'id' => $row['id'],
            'previous_fence' => $previousFence,
        ]);
        if (1 !== $affected) {
            throw new RuntimeException('The locked queue fence changed unexpectedly.');
        }
        if (null !== ($row['run_id'] ?? null)) {
            $runAffected = $db->executeStatement(<<<'SQL'
UPDATE backup_runs
SET claim_token = :token, claim_fence = :fence, revision = revision + 1
WHERE id = :run AND request_id = :request
SQL, [
                'token' => $token,
                'fence' => $fence,
                'run' => $this->binary($row['run_id']),
                'request' => $this->binary($row['id'] ?? null),
            ]);
            if (1 !== $runAffected) {
                throw new RuntimeException('The active backup run fence could not be transferred.');
            }
        }
        $requestId = $this->binary($row['id'] ?? null);
        $this->appendEvent($db, $requestId, $eventType, $state, $command->now, $fence);

        return new ClaimedBackupRequest(
            $requestId,
            $token,
            $fence,
            $expiry,
            $this->binary($row['node_id'] ?? null),
            $this->binary($row['target_id'] ?? null),
            $this->decimal($row['expected_size_bytes'] ?? null)
                ?? throw new RuntimeException('Invalid expected size.'),
            $state,
            $this->nullableBinary($row['run_id'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private function defer(Connection $db, array $row, DateTimeImmutable $now, string $detail): void
    {
        $availableAt = $now->add(new DateInterval('PT'.$this->deferSeconds.'S'));
        $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_requests
SET state = 'retry_wait', available_at = :available_at, revision = revision + 1, updated_at = :now
WHERE id = :id AND state IN ('pending', 'retry_wait')
SQL, [
            'available_at' => $this->format($availableAt),
            'now' => $this->format($now),
            'id' => $row['id'],
        ]);
        if (1 !== $affected) {
            throw new RuntimeException('A locked queue request could not be deferred.');
        }
        $this->appendEvent($db, $this->binary($row['id'] ?? null), 'deferred', 'retry_wait', $now, null, $detail);
    }

    private function appendEvent(
        Connection $db,
        string $requestId,
        string $type,
        string $state,
        DateTimeImmutable $at,
        ?int $fence = null,
        ?string $detail = null,
    ): void {
        $sequence = $this->integer($db->fetchOne(<<<'SQL'
SELECT COALESCE(MAX(sequence_no), 0) + 1
FROM backup_request_events
WHERE request_id = :id
SQL, ['id' => $requestId]));
        $db->insert('backup_request_events', [
            'id' => $this->eventId($requestId, $sequence),
            'request_id' => $requestId,
            'sequence_no' => $sequence,
            'event_type' => $type,
            'state' => $state,
            'claim_fence' => $fence,
            'occurred_at' => $this->format($at),
            'detail_code' => $detail,
        ]);
    }

    private function eventId(string $requestId, int $sequence): string
    {
        return substr(hash('sha256', 'queue-event' . "\0" . $requestId . (string) $sequence, true), 0, 16);
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));

        return false === $date ? null : $date;
    }

    private function integer(mixed $value): int
    {
        $integer = $this->nullableInteger($value);
        if (null === $integer) {
            throw new RuntimeException('Invalid queue integer.');
        }

        return $integer;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && !str_starts_with($value, '0')) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($maximum)
                || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0)) {
                return (int) $value;
            }
        }
        if ('0' === $value) {
            return 0;
        }

        return null;
    }

    private function decimal(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (is_string($value) || (is_int($value) && $value >= 0)) {
            return (new UInt64Decimal((string) $value))->value;
        }

        throw new RuntimeException('Invalid queue UInt64.');
    }

    private function decimalLessThan(string $left, string $right): bool
    {
        $length = strlen($left) <=> strlen($right);

        return $length < 0 || (0 === $length && strcmp($left, $right) < 0);
    }

    private function binary(mixed $value): string
    {
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('Invalid queue identifier.');
        }

        return $value;
    }

    private function nullableBinary(mixed $value): ?string
    {
        return null === $value ? null : $this->binary($value);
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Invalid queue text.');
        }

        return $value;
    }
}
