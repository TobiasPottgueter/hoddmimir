<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Scheduler\Shadow\AutomaticShadowCandidate;
use App\Application\Scheduler\Shadow\AutomaticShadowEvaluationSource;
use App\Domain\Shared\UInt64Decimal;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\Compression;
use App\Domain\Policy\FailureNotificationRecipients;
use App\Domain\Policy\PolicyId;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyThresholds;
use App\Domain\Policy\ResolvedBackupPolicy;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use App\Domain\Target\BackupTargetId;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalAutomaticShadowEvaluationSource implements AutomaticShadowEvaluationSource
{
    public function __construct(private Connection $connection) {}

    public function candidates(CollectorLease $lease): array
    {
        return $this->connection->transactional(function (Connection $connection) use ($lease): array {
            $this->assertFence($connection, $lease);
            $rows = $connection->fetchAllAssociative(<<<'SQL'
SELECT connection.id AS connection_id, connection.enabled AS connection_enabled,
       cluster.id AS cluster_id, cluster.inventory_state AS cluster_state,
       guest.id AS guest_id, guest.inventory_state AS guest_state, guest.is_template,
       LEAST(guest.last_seen_at, storage.last_seen_at) AS inventory_observed_at,
       placement.node_id, placement.placement_revision, placement.observed_at AS placement_observed_at,
       node.api_status, node.inventory_state AS node_state,
       policy.id AS policy_id, policy.revision AS policy_revision, policy.status AS policy_status,
       policy.policy_priority, policy.backup_mode, policy.compression, policy.schedule,
       policy.legacy_maxfiles, policy.keep_all, policy.keep_last, policy.keep_hourly, policy.keep_daily,
       policy.keep_weekly, policy.keep_monthly, policy.keep_yearly, policy.retention_execution_enabled,
       policy.failure_notification_recipients_json,
       guest_override.backup_mode AS guest_backup_mode, guest_override.compression AS guest_compression,
       guest_override.legacy_maxfiles AS guest_legacy_maxfiles, guest_override.keep_all AS guest_keep_all,
       guest_override.keep_last AS guest_keep_last, guest_override.keep_hourly AS guest_keep_hourly,
       guest_override.keep_daily AS guest_keep_daily, guest_override.keep_weekly AS guest_keep_weekly,
       guest_override.keep_monthly AS guest_keep_monthly, guest_override.keep_yearly AS guest_keep_yearly,
       COALESCE((SELECT capability.version_major FROM proxmox_capability_snapshots capability
         WHERE capability.connection_id = policy.connection_id ORDER BY capability.last_observed_at DESC, capability.id DESC LIMIT 1), 0) AS pve_major,
       EXISTS(SELECT 1 FROM backup_policy_assignments assignment
          WHERE assignment.policy_id = policy.id AND assignment.status = 'active'
            AND assignment.selection_value = 'include'
            AND ((assignment.scope = 'global')
              OR (assignment.scope = 'connection' AND assignment.subject_connection_id = guest.connection_id)
              OR (assignment.scope = 'cluster' AND assignment.subject_cluster_id = guest.cluster_id)
              OR (assignment.scope = 'node' AND assignment.node_id = placement.node_id)
              OR (assignment.scope = 'guest' AND assignment.guest_id = guest.id))) AS selection_included,
       EXISTS(SELECT 1 FROM backup_policy_assignments assignment
          WHERE assignment.policy_id = policy.id AND assignment.status = 'active'
            AND assignment.selection_value = 'exclude'
            AND ((assignment.scope = 'global')
              OR (assignment.scope = 'connection' AND assignment.subject_connection_id = guest.connection_id)
              OR (assignment.scope = 'cluster' AND assignment.subject_cluster_id = guest.cluster_id)
              OR (assignment.scope = 'node' AND assignment.node_id = placement.node_id)
              OR (assignment.scope = 'guest' AND assignment.guest_id = guest.id))) AS explicitly_excluded,
       target.id AS target_id, target.revision AS target_revision, target.status AS target_status, target.pbs_connection_id,
       storage.storage_type AS target_storage_type,
       target.minimum_free_bytes, target.fixed_parallel_limit,
       EXISTS(SELECT 1 FROM backup_target_allowed_nodes allowed WHERE allowed.target_id = target.id AND allowed.node_id = placement.node_id) AS target_node_allowed,
       storage.disabled AS storage_disabled, storage.inventory_state AS storage_state,
       node_storage.enabled AS storage_enabled, node_storage.active AS storage_active,
       node_storage.available_bytes, pbs_capacity.available_bytes AS pbs_available_bytes,
       CASE WHEN storage.storage_type <> 'pbs' THEN node_storage.observed_at
            WHEN node_storage.observed_at IS NULL OR pbs_capacity.observed_at IS NULL THEN NULL
            ELSE LEAST(node_storage.observed_at, pbs_capacity.observed_at) END AS capacity_observed_at,
       CASE WHEN node_slot.node_id IS NULL THEN 1
            ELSE node_slot.slot_limit = 1 AND node_slot.slots_used < node_slot.slot_limit END AS node_concurrency_available,
       CASE WHEN target.fixed_parallel_limit IS NULL OR target.fixed_parallel_limit < 1 THEN 0
            WHEN target_slot.target_id IS NULL THEN 1
            ELSE target_slot.slot_limit = target.fixed_parallel_limit
              AND target_slot.slots_used < target_slot.slot_limit END AS target_concurrency_available,
       (storage.storage_type <> 'pbs' OR (target.pbs_connection_id IS NOT NULL
         AND target.pbs_datastore_id IS NOT NULL AND mapping.storage_id IS NOT NULL
         AND EXISTS(SELECT 1 FROM proxmox_connections pbs_connection
           WHERE pbs_connection.id = target.pbs_connection_id
             AND pbs_connection.product = 'pbs' AND pbs_connection.enabled = 1)
         AND EXISTS(SELECT 1 FROM proxmox_connection_endpoints pbs_endpoint
           WHERE pbs_endpoint.connection_id = target.pbs_connection_id
             AND pbs_endpoint.enabled = 1
             AND pbs_endpoint.host = mapping.server AND pbs_endpoint.port = mapping.port)
         AND pbs_datastore.inventory_state = 'active' AND pbs_datastore.allows_backup_writes = 1
         AND pbs_capacity.semantics = 'datastore_filesystem' AND mapping.datastore = pbs_datastore.datastore_name
         AND ((target.pbs_namespace_id IS NULL AND mapping.namespace IS NULL)
           OR (target.pbs_namespace_id IS NOT NULL
             AND COALESCE(mapping.namespace, '') = pbs_namespace.namespace_path))
         AND (target.pbs_namespace_id IS NULL OR pbs_namespace.inventory_state = 'active'))) AS pbs_mapping_valid,
       mapping.observed_at AS pbs_observed_at,
       executor.observed_at AS executor_observed_at,
       COALESCE(executor.authorized, 0) AS executor_authorized,
       NOT EXISTS(SELECT 1 FROM backup_requests active_request
         WHERE active_request.connection_id = guest.connection_id
           AND active_request.cluster_id = guest.cluster_id
           AND active_request.guest_id = guest.id
           AND active_request.state IN ('pending', 'retry_wait', 'leased', 'starting', 'running', 'reconcile_required')) AS active_request_absent,
       backup_state.last_success_at, backup_state.baseline_bytes,
       (guest.provisioned_size_bytes IS NOT NULL OR backup_state.last_success_size_bytes IS NOT NULL) AS expected_backup_size_present,
       policy.maximum_age_seconds, policy.bytes_written_threshold, policy.cooldown_seconds,
       write_state.diskwrite_bytes AS current_bytes, write_state.observed_at AS write_state_observed_at
FROM backup_policies policy
JOIN proxmox_connections connection ON connection.id = policy.connection_id
JOIN pve_clusters cluster ON cluster.connection_id = policy.connection_id AND cluster.id = policy.cluster_id
JOIN guests guest ON guest.connection_id = policy.connection_id AND guest.cluster_id = policy.cluster_id
JOIN backup_targets target ON target.connection_id = policy.connection_id
    AND target.cluster_id = policy.cluster_id AND target.id = policy.target_id
JOIN pve_storages storage ON storage.connection_id = target.connection_id
    AND storage.cluster_id = target.cluster_id AND storage.id = target.storage_id
LEFT JOIN guest_placements placement ON placement.guest_id = guest.id
LEFT JOIN pve_nodes node ON node.id = placement.node_id
LEFT JOIN pve_node_storage_state node_storage ON node_storage.node_id = placement.node_id
    AND node_storage.storage_id = target.storage_id
LEFT JOIN pve_storage_pbs_mappings mapping ON mapping.storage_id = target.storage_id
LEFT JOIN pbs_datastores pbs_datastore ON pbs_datastore.connection_id = target.pbs_connection_id AND pbs_datastore.id = target.pbs_datastore_id
LEFT JOIN pbs_namespaces pbs_namespace ON pbs_namespace.datastore_id = target.pbs_datastore_id AND pbs_namespace.id = target.pbs_namespace_id
LEFT JOIN pbs_datastore_capacity_state pbs_capacity ON pbs_capacity.datastore_id = target.pbs_datastore_id
LEFT JOIN backup_node_slots node_slot ON node_slot.connection_id = guest.connection_id
    AND node_slot.cluster_id = guest.cluster_id AND node_slot.node_id = placement.node_id
LEFT JOIN backup_target_slots target_slot ON target_slot.connection_id = target.connection_id
    AND target_slot.cluster_id = target.cluster_id AND target_slot.target_id = target.id
LEFT JOIN current_executor_permission_evidence executor
    ON executor.connection_id = guest.connection_id AND executor.cluster_id = guest.cluster_id
    AND executor.target_id = target.id AND executor.node_id = placement.node_id
    AND executor.storage_id = target.storage_id AND executor.guest_id = guest.id
LEFT JOIN guest_write_states write_state ON write_state.guest_id = guest.id
LEFT JOIN backup_policy_guest_overrides guest_override ON guest_override.policy_id = policy.id
    AND guest_override.guest_id = guest.id AND guest_override.status = 'active'
LEFT JOIN guest_backup_state backup_state ON backup_state.guest_id = guest.id
    AND backup_state.policy_id = policy.id AND backup_state.target_id = target.id
WHERE policy.status = 'enabled' AND target.status = 'enabled'
ORDER BY guest.id, policy.id, target.id
SQL);
            $this->assertFence($connection, $lease);
            return array_map(fn (array $row): AutomaticShadowCandidate => $this->map($row), $rows);
        });
    }

    public function recordCounterReset(CollectorLease $lease, AutomaticShadowCandidate $candidate, DateTimeImmutable $detectedAt): void
    {
        if (null === $candidate->currentBytes || null === $candidate->baselineBytes
            || $candidate->baselineBytes->lessThanOrEqual($candidate->currentBytes)) {
            throw new RuntimeException('A write-counter reset requires a lower current counter.');
        }
        $this->connection->transactional(function (Connection $connection) use ($lease, $candidate, $detectedAt): void {
            $this->assertFence($connection, $lease);
            $state = $connection->fetchAssociative(
                'SELECT baseline_bytes FROM guest_backup_state WHERE guest_id = :guest AND policy_id = :policy AND target_id = :target FOR UPDATE',
                ['guest' => $candidate->guestId, 'policy' => $candidate->policyId, 'target' => $candidate->targetId],
            );
            if (false === $state || $this->decimal($state['baseline_bytes'] ?? null)?->value !== $candidate->baselineBytes->value) {
                throw new RuntimeException('The guest backup baseline changed concurrently.');
            }
            $id = substr(hash('sha256', 'reset'."\0".$lease->token->binary().$candidate->guestId.$candidate->policyId.$candidate->targetId, true), 0, 16);
            $connection->insert('guest_write_counter_resets', [
                'id' => $id, 'cycle_token' => $lease->token->binary(),
                'collector_fencing_token' => $lease->fencingToken,
                'guest_id' => $candidate->guestId, 'policy_id' => $candidate->policyId,
                'target_id' => $candidate->targetId,
                'previous_baseline_bytes' => $candidate->baselineBytes->value,
                'current_bytes' => $candidate->currentBytes->value,
                'detected_at' => $this->format($detectedAt),
            ]);
            $affected = $connection->executeStatement(<<<'SQL'
UPDATE guest_backup_state
SET baseline_bytes = :current, baseline_observed_at = :observed, updated_at = :updated
WHERE guest_id = :guest AND policy_id = :policy AND target_id = :target
  AND baseline_bytes = :previous
SQL, [
                'current' => $candidate->currentBytes->value,
                'observed' => $this->format($candidate->writeStateObservedAt ?? $detectedAt),
                'updated' => $this->format($detectedAt), 'guest' => $candidate->guestId,
                'policy' => $candidate->policyId, 'target' => $candidate->targetId,
                'previous' => $candidate->baselineBytes->value,
            ]);
            if (1 !== $affected) { throw new RuntimeException('The guest backup baseline changed concurrently.'); }
            $this->assertFence($connection, $lease);
        });
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): AutomaticShadowCandidate
    {
        $binary = static function (mixed $value): string {
            if (!is_string($value) || 16 !== strlen($value)) { throw new RuntimeException('MariaDB returned an invalid shadow identifier.'); }
            return $value;
        };
        $policyEvidence = $this->resolvedPolicyEvidence($row);
        return new AutomaticShadowCandidate(
            $binary($row['connection_id'] ?? null), $this->bool($row, 'connection_enabled'),
            $binary($row['cluster_id'] ?? null), 'active' === ($row['cluster_state'] ?? null),
            $binary($row['guest_id'] ?? null), 'active' === ($row['guest_state'] ?? null),
            null === ($row['is_template'] ?? null) ? null : $this->bool($row, 'is_template'),
            $this->date($row['inventory_observed_at'] ?? null) ?? throw new RuntimeException('Missing inventory timestamp.'),
            null === ($row['node_id'] ?? null) ? null : $binary($row['node_id']),
            'online' === ($row['api_status'] ?? null) && 'active' === ($row['node_state'] ?? null),
            $this->int($row['placement_revision'] ?? null), $this->date($row['placement_observed_at'] ?? null),
            $binary($row['policy_id'] ?? null), $this->int($row['policy_revision'] ?? null) ?? 0,
            'enabled' === ($row['policy_status'] ?? null), $policyEvidence['compatible'], $policyEvidence['hash'],
            $this->bool($row, 'selection_included'), $this->bool($row, 'explicitly_excluded'),
            $binary($row['target_id'] ?? null), $this->int($row['target_revision'] ?? null) ?? 0,
            'enabled' === ($row['target_status'] ?? null), $this->bool($row, 'target_node_allowed'),
            !$this->bool($row, 'storage_disabled') && 'active' === ($row['storage_state'] ?? null)
                && $this->bool($row, 'storage_enabled'),
            $this->bool($row, 'storage_active'), $this->date($row['capacity_observed_at'] ?? null),
            $this->effectiveCapacity($row), $this->decimal($row['minimum_free_bytes'] ?? null),
            $this->bool($row, 'expected_backup_size_present'),
            $this->bool($row, 'node_concurrency_available'), $this->bool($row, 'target_concurrency_available'),
            $this->bool($row, 'pbs_mapping_valid'),
            'pbs' !== ($row['target_storage_type'] ?? null)
                ? $this->date($row['inventory_observed_at'] ?? null)
                : $this->date($row['pbs_observed_at'] ?? null),
            $this->date($row['executor_observed_at'] ?? null),
            $this->bool($row, 'executor_authorized'), $this->bool($row, 'active_request_absent'),
            $this->date($row['last_success_at'] ?? null), $this->int($row['maximum_age_seconds'] ?? null),
            $this->decimal($row['current_bytes'] ?? null), $this->date($row['write_state_observed_at'] ?? null),
            $this->decimal($row['baseline_bytes'] ?? null), $this->decimal($row['bytes_written_threshold'] ?? null),
            $this->int($row['cooldown_seconds'] ?? null),
            $policyEvidence['json'],
            $this->int($row['policy_priority'] ?? null) ?? 0,
        );
    }

    private function assertFence(Connection $connection, CollectorLease $lease): void
    {
        $row = $connection->fetchAssociative("SELECT lease_owner, lease_token, lease_fencing_token, lease_expires_at FROM collector_schedule WHERE schedule_name = 'inventory' FOR UPDATE");
        $now = $this->date($connection->fetchOne('SELECT UTC_TIMESTAMP(6)'));
        if (false === $row || null === $now || !is_string($row['lease_owner'] ?? null) || !hash_equals($lease->ownerId->bytes, $row['lease_owner'])
            || !is_string($row['lease_token'] ?? null) || !hash_equals($lease->token->binary(), $row['lease_token'])
            || $this->int($row['lease_fencing_token'] ?? null) !== $lease->fencingToken
            || null === ($expires = $this->date($row['lease_expires_at'] ?? null)) || $expires <= $now) {
            throw new CollectorLeaseOwnershipLost('The shadow source lost its collector lease.');
        }
    }

    /** @param array<string, mixed> $row */
    private function bool(array $row, string $key): bool { return 1 === $this->int($row[$key] ?? null); }
    private function int(mixed $value): ?int {
        if (null === $value) return null;
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && ctype_digit($value) && strlen($value) < 19) return (int) $value;
        return null;
    }
    private function decimal(mixed $value): ?UInt64Decimal {
        if (null === $value) return null;
        if (!is_int($value) && !is_string($value)) throw new RuntimeException('MariaDB returned an invalid UInt64 value.');
        return new UInt64Decimal((string) $value);
    }
    /** @param array<string, mixed> $row */
    private function effectiveCapacity(array $row): ?UInt64Decimal {
        $pve = $this->decimal($row['available_bytes'] ?? null);
        if (null === $pve) return null;
        if ('pbs' !== ($row['target_storage_type'] ?? null)) return $pve;
        if (null === ($row['pbs_available_bytes'] ?? null)) return null;
        $pbs = $this->decimal($row['pbs_available_bytes']);
        return null !== $pbs && $pbs->lessThanOrEqual($pve) ? $pbs : $pve;
    }
    private function date(mixed $value): ?DateTimeImmutable {
        if (!is_string($value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        return false === $date ? null : $date;
    }
    /** @param array<string, mixed> $row
     *  @return array{hash: string, compatible: bool, json: ?string}
     */
    private function resolvedPolicyEvidence(array $row): array {
        $policyRetention = $this->retention($row, '');
        $guestRetention = $this->retention($row, 'guest_');
        $retention = $guestRetention ?? $policyRetention ?? throw new RuntimeException('An enabled policy lacks retention.');
        $pveMajor = $this->int($row['pve_major'] ?? null) ?? 0;
        if ($pveMajor < 7 || $pveMajor > 9 || !$retention->supportsPveMajor($pveMajor)) {
            $evidence = json_encode([
                'policy' => bin2hex($this->binary($row['policy_id'] ?? null)),
                'revision' => $this->int($row['policy_revision'] ?? null) ?? 0,
                'pveMajor' => $pveMajor,
                'retention' => $retention->signature(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            return ['hash' => hash('sha256', "incompatible-policy-retention\0".$evidence, true), 'compatible' => false, 'json' => null];
        }
        $resolved = new ResolvedBackupPolicy(
            new PolicyId($this->binary($row['policy_id'] ?? null)),
            new PolicyRevision($this->int($row['policy_revision'] ?? null) ?? 0),
            new BackupTargetId($this->binary($row['target_id'] ?? null)),
            $pveMajor,
            BackupMode::from($this->text($row['guest_backup_mode'] ?? $row['backup_mode'] ?? null)),
            Compression::from($this->text($row['guest_compression'] ?? $row['compression'] ?? null)),
            $retention,
            $this->bool($row, 'retention_execution_enabled') && 'pbs' !== ($row['target_storage_type'] ?? null)
                ? $retention
                : null,
            new PolicyPriority($this->int($row['policy_priority'] ?? null) ?? -1),
            new PolicyThresholds($this->int($row['maximum_age_seconds'] ?? null), $this->decimal($row['bytes_written_threshold'] ?? null)?->value, $this->int($row['cooldown_seconds'] ?? null)),
            Schedule::from($this->text($row['schedule'] ?? null)),
            $this->failureRecipients($row['failure_notification_recipients_json'] ?? null),
        );
        $json = $resolved->canonicalJson();
        return [
            'hash' => hash('sha256', $json, true),
            'compatible' => true,
            'json' => $json,
        ];
    }
    private function failureRecipients(mixed $value): FailureNotificationRecipients {
        if (!is_string($value)) throw new RuntimeException('Policy failure recipients are invalid.');
        try { $addresses = json_decode($value, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RuntimeException('Policy failure recipients are invalid.', 0, $error); }
        if (!is_array($addresses) || !array_is_list($addresses)) throw new RuntimeException('Policy failure recipients are invalid.');
        foreach ($addresses as $address) if (!is_string($address)) throw new RuntimeException('Policy failure recipients are invalid.');
        /** @var list<string> $addresses */
        return new FailureNotificationRecipients($addresses);
    }
    /** @param array<string, mixed> $row */
    private function retention(array $row, string $prefix): ?RetentionPolicy {
        $legacy = $this->int($row[$prefix.'legacy_maxfiles'] ?? null);
        if (null !== $legacy) return RetentionPolicy::legacyMaxFiles($legacy);
        $keys = ['keep_all', 'keep_last', 'keep_hourly', 'keep_daily', 'keep_weekly', 'keep_monthly', 'keep_yearly'];
        if ([] === array_filter($keys, fn (string $key): bool => null !== ($row[$prefix.$key] ?? null))) return null;
        return RetentionPolicy::prune(
            null === ($row[$prefix.'keep_all'] ?? null) ? null : $this->bool($row, $prefix.'keep_all'),
            ...array_map(fn (string $key): ?int => $this->int($row[$prefix.$key] ?? null), array_slice($keys, 1)),
        );
    }
    private function binary(mixed $value): string { if (!is_string($value) || 16 !== strlen($value)) throw new RuntimeException('MariaDB returned an invalid shadow identifier.'); return $value; }
    private function text(mixed $value): string { if (!is_string($value) || '' === $value) throw new RuntimeException('MariaDB returned invalid policy text.'); return $value; }
    private function format(DateTimeImmutable $value): string { return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
}
