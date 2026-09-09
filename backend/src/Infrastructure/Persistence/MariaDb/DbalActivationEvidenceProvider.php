<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Configuration\Policy\PolicyActivationEvidence;
use App\Application\Configuration\Policy\PolicyActivationEvidenceProvider;
use App\Application\Configuration\Target\TargetCandidateEvidence;
use App\Application\Configuration\Target\TargetCandidateEvidenceProvider;
use App\Application\Configuration\Target\TargetExecutorEvidenceProvider;
use App\Domain\Policy\PolicyId;
use App\Domain\Target\ActivationEvidenceObservation;
use App\Domain\Target\BackupTargetId;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalActivationEvidenceProvider implements TargetCandidateEvidenceProvider, TargetExecutorEvidenceProvider, PolicyActivationEvidenceProvider
{
    public function __construct(private Connection $connection)
    {
    }

    public function executorEvidence(BackupTargetId $id): ActivationEvidenceObservation
    {
        return $this->executorEvidenceBatch([$id])[$id->toHex()];
    }

    public function executorEvidenceBatch(array $ids): array
    {
        $result = [];
        $binaryIds = [];
        foreach ($ids as $id) {
            $result[$id->toHex()] = new ActivationEvidenceObservation(null, null);
            $binaryIds[$id->toHex()] = $id->binary();
        }
        if ([] === $binaryIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT allowed.target_id, COUNT(allowed.node_id) AS expected_count, COUNT(evidence.node_id) AS evidence_count,
       MIN(evidence.authorized) AS accepted, MIN(evidence.observed_at) AS observed_at,
       MAX(evidence.observed_at) AS newest_observed_at
FROM backup_target_allowed_nodes allowed
JOIN backup_targets target
  ON target.id=allowed.target_id
 AND target.connection_id=allowed.connection_id
 AND target.cluster_id=allowed.cluster_id
LEFT JOIN current_executor_permission_evidence evidence
  ON evidence.connection_id=allowed.connection_id AND evidence.cluster_id=allowed.cluster_id
 AND evidence.node_id=allowed.node_id
 AND evidence.target_id=allowed.target_id AND evidence.guest_id IS NULL
 AND evidence.storage_id=target.storage_id
WHERE allowed.target_id IN (:target_ids)
GROUP BY allowed.target_id
SQL, ['target_ids' => array_values($binaryIds)], ['target_ids' => ArrayParameterType::BINARY]);
        foreach ($rows as $row) {
            $targetId = $this->binaryId($row['target_id'] ?? null);
            $hex = bin2hex($targetId);
            if (!isset($result[$hex])) {
                throw new RuntimeException('MariaDB returned activation evidence for an unrequested target.');
            }
            if ($this->integer($row['expected_count'] ?? null) < 1
                || $this->integer($row['expected_count'] ?? null) !== $this->integer($row['evidence_count'] ?? null)) {
                continue;
            }
            $result[$hex] = new ActivationEvidenceObservation(
                $this->boolean($row['accepted'] ?? null),
                $this->date($row['observed_at'] ?? null),
                $this->date($row['newest_observed_at'] ?? null),
            );
        }
        return $result;
    }

    public function candidateEvidence(BackupTargetId $id): TargetCandidateEvidence
    {
        return $this->candidateEvidenceBatch([$id])[$id->toHex()];
    }

    public function candidateEvidenceBatch(array $ids): array
    {
        $missing = new ActivationEvidenceObservation(null, null);
        $result = [];
        $binaryIds = [];
        foreach ($ids as $id) {
            $result[$id->toHex()] = new TargetCandidateEvidence($missing, $missing, $missing);
            $binaryIds[$id->toHex()] = $id->binary();
        }
        if ([] === $binaryIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT target.id AS target_id, connection.enabled AS connection_enabled, connection.product AS connection_product,
       cluster.inventory_state AS cluster_state,
       cluster.last_seen_at AS cluster_observed_at,
       storage.supports_backup, JSON_CONTAINS(storage.content_json, JSON_QUOTE('backup')) AS backup_content_configured,
       storage.disabled AS storage_disabled, storage.inventory_state AS storage_state,
       storage.last_seen_at AS storage_observed_at, target.minimum_free_bytes, storage.storage_type,
       target.pbs_connection_id, target.pbs_datastore_id, target.pbs_namespace_id,
       COUNT(allowed.node_id) AS allowed_count, COUNT(node.id) AS node_count, COUNT(state.node_id) AS state_count,
       MIN(node.api_status = 'online') AS nodes_online,
       MIN(node.inventory_state = 'active') AS nodes_active_inventory,
       MIN(storage.node_allowlist_json IS NULL
           OR JSON_CONTAINS(storage.node_allowlist_json, JSON_QUOTE(node.node_name))) AS nodes_storage_configured,
       MIN(node.last_seen_at) AS node_observed_at, MAX(node.last_seen_at) AS node_newest_observed_at,
       MIN(state.enabled) AS nodes_enabled, MIN(state.active) AS nodes_active,
       MIN(state.available_bytes) AS available_bytes, MIN(state.observed_at) AS capacity_observed_at,
       MAX(state.observed_at) AS capacity_newest_observed_at,
       mapping.namespace AS pbs_mapping_namespace, mapping.observed_at AS pbs_mapping_observed_at,
       EXISTS (
           SELECT 1 FROM proxmox_connection_endpoints pbs_endpoint
           WHERE pbs_endpoint.connection_id=target.pbs_connection_id
             AND BINARY pbs_endpoint.host=BINARY mapping.server
             AND pbs_endpoint.port=mapping.port AND pbs_endpoint.enabled=1
       ) AS pbs_endpoint_matches,
       pbs_connection.enabled AS pbs_connection_enabled, pbs_connection.product AS pbs_connection_product,
       pbs_server.id AS pbs_server_id, pbs_server.last_seen_at AS pbs_server_observed_at,
       pbs_datastore.id AS matched_pbs_datastore_id,
       pbs_datastore.inventory_state AS pbs_datastore_state,
       pbs_datastore.allows_backup_writes AS pbs_datastore_writable,
       pbs_datastore.last_seen_at AS pbs_datastore_observed_at,
       pbs_namespace.id AS matched_pbs_namespace_id,
       pbs_namespace.inventory_state AS pbs_namespace_state,
       pbs_namespace.last_seen_at AS pbs_namespace_observed_at,
       pbs_capacity.semantics AS pbs_capacity_semantics,
       pbs_capacity.available_bytes AS pbs_available_bytes,
       pbs_capacity.observed_at AS pbs_capacity_observed_at
FROM backup_targets target
JOIN proxmox_connections connection ON connection.id=target.connection_id
JOIN pve_clusters cluster
  ON cluster.connection_id=target.connection_id
 AND cluster.id=target.cluster_id
JOIN pve_storages storage
  ON storage.connection_id=target.connection_id
 AND storage.cluster_id=target.cluster_id
 AND storage.id=target.storage_id
LEFT JOIN backup_target_allowed_nodes allowed
  ON allowed.target_id=target.id
 AND allowed.connection_id=target.connection_id
 AND allowed.cluster_id=target.cluster_id
LEFT JOIN pve_nodes node
  ON node.connection_id=allowed.connection_id
 AND node.cluster_id=allowed.cluster_id
 AND node.id=allowed.node_id
LEFT JOIN pve_node_storage_state state ON state.connection_id=allowed.connection_id
 AND state.cluster_id=allowed.cluster_id AND state.node_id=allowed.node_id AND state.storage_id=target.storage_id
LEFT JOIN pve_storage_pbs_mappings mapping ON mapping.storage_id=target.storage_id
LEFT JOIN proxmox_connections pbs_connection ON pbs_connection.id=target.pbs_connection_id
LEFT JOIN pbs_servers pbs_server ON pbs_server.connection_id=target.pbs_connection_id
LEFT JOIN pbs_datastores pbs_datastore
  ON pbs_datastore.connection_id=target.pbs_connection_id
 AND pbs_datastore.server_id=pbs_server.id
 AND pbs_datastore.id=target.pbs_datastore_id
 AND BINARY pbs_datastore.datastore_name=BINARY mapping.datastore
LEFT JOIN pbs_namespaces pbs_namespace
  ON pbs_namespace.connection_id=target.pbs_connection_id
 AND pbs_namespace.server_id=pbs_server.id
 AND pbs_namespace.datastore_id=target.pbs_datastore_id
 AND pbs_namespace.id=target.pbs_namespace_id
 AND BINARY pbs_namespace.namespace_path=BINARY COALESCE(mapping.namespace, '')
LEFT JOIN pbs_datastore_capacity_state pbs_capacity ON pbs_capacity.datastore_id=target.pbs_datastore_id
WHERE target.id IN (:target_ids)
GROUP BY target.id
SQL, ['target_ids' => array_values($binaryIds)], ['target_ids' => ArrayParameterType::BINARY]);
        foreach ($rows as $row) {
            $targetId = $this->binaryId($row['target_id'] ?? null);
            $hex = bin2hex($targetId);
            if (!isset($result[$hex])) {
                throw new RuntimeException('MariaDB returned activation evidence for an unrequested target.');
            }
            $pbs = 'pbs' === $this->text($row['storage_type'] ?? null);
            $completeNodes = $this->integer($row['allowed_count'] ?? null) > 0
                && $this->integer($row['allowed_count'] ?? null) === $this->integer($row['node_count'] ?? null);
            $nodesUsable = $completeNodes
                && $this->boolean($row['nodes_online'] ?? null)
                && $this->boolean($row['nodes_active_inventory'] ?? null)
                && $this->boolean($row['nodes_storage_configured'] ?? null);
            $pbsNamespaceConfigured = null !== ($row['pbs_namespace_id'] ?? null);
            $pbsNamespaceAccepted = !$pbsNamespaceConfigured
                ? (null === $row['pbs_mapping_namespace'] || '' === $row['pbs_mapping_namespace'])
                : null !== ($row['matched_pbs_namespace_id'] ?? null)
                    && 'active' === ($row['pbs_namespace_state'] ?? null);
            $pbsInventoryAccepted = !$pbs || (
                null !== ($row['pbs_connection_id'] ?? null)
                && null !== ($row['pbs_datastore_id'] ?? null)
                && null !== ($row['pbs_mapping_observed_at'] ?? null)
                && $this->boolean($row['pbs_endpoint_matches'] ?? null)
                && $this->boolean($row['pbs_connection_enabled'] ?? null)
                && 'pbs' === ($row['pbs_connection_product'] ?? null)
                && null !== ($row['pbs_server_id'] ?? null)
                && null !== ($row['matched_pbs_datastore_id'] ?? null)
                && 'active' === ($row['pbs_datastore_state'] ?? null)
                && $this->boolean($row['pbs_datastore_writable'] ?? null)
                && $pbsNamespaceAccepted
            );
            $inventoryAccepted = $this->boolean($row['connection_enabled'] ?? null)
                && 'pve' === $this->text($row['connection_product'] ?? null)
                && 'active' === $this->text($row['cluster_state'] ?? null)
                && $this->boolean($row['supports_backup'] ?? null)
                && $this->boolean($row['backup_content_configured'] ?? null)
                && !$this->boolean($row['storage_disabled'] ?? null)
                && 'active' === $this->text($row['storage_state'] ?? null)
                && $nodesUsable
                && $pbsInventoryAccepted;
            $inventoryDates = [
                [$row['cluster_observed_at'] ?? null, $row['cluster_observed_at'] ?? null],
                [$row['storage_observed_at'] ?? null, $row['storage_observed_at'] ?? null],
                [$row['node_observed_at'] ?? null, $row['node_newest_observed_at'] ?? null],
            ];
            if ($pbs) {
                array_push(
                    $inventoryDates,
                    [$row['pbs_mapping_observed_at'] ?? null, $row['pbs_mapping_observed_at'] ?? null],
                    [$row['pbs_server_observed_at'] ?? null, $row['pbs_server_observed_at'] ?? null],
                    [$row['pbs_datastore_observed_at'] ?? null, $row['pbs_datastore_observed_at'] ?? null],
                );
                if ($pbsNamespaceConfigured) {
                    $inventoryDates[] = [
                        $row['pbs_namespace_observed_at'] ?? null,
                        $row['pbs_namespace_observed_at'] ?? null,
                    ];
                }
            }
            [$inventoryObservedAt, $inventoryNewestObservedAt] = $this->dateRange($inventoryDates);
            $inventory = new ActivationEvidenceObservation(
                $inventoryAccepted,
                $inventoryObservedAt,
                $inventoryNewestObservedAt,
            );
            $allowed = $this->integer($row['allowed_count'] ?? null);
            $complete = $nodesUsable && $allowed === $this->integer($row['state_count'] ?? null);
            $pbsCapacityComplete = !$pbs || (
                null !== ($row['pbs_capacity_semantics'] ?? null)
                && null !== ($row['pbs_available_bytes'] ?? null)
                && null !== ($row['pbs_capacity_observed_at'] ?? null)
            );
            $capacityAccepted = $complete && $pbsCapacityComplete
                && $this->boolean($row['nodes_enabled'] ?? null) && $this->boolean($row['nodes_active'] ?? null)
                && null !== ($row['available_bytes'] ?? null) && null !== ($row['minimum_free_bytes'] ?? null)
                && $this->decimalGreaterOrEqual($row['available_bytes'], $row['minimum_free_bytes'])
                && (!$pbs || ('datastore_filesystem' === ($row['pbs_capacity_semantics'] ?? null)
                    && $this->decimalGreaterOrEqual($row['pbs_available_bytes'], $row['minimum_free_bytes'])));
            [$capacityObservedAt, $capacityNewestObservedAt] = $complete && $pbsCapacityComplete
                ? $this->dateRange($pbs
                    ? [
                        [$row['capacity_observed_at'] ?? null, $row['capacity_newest_observed_at'] ?? null],
                        [$row['pbs_capacity_observed_at'] ?? null, $row['pbs_capacity_observed_at'] ?? null],
                    ]
                    : [[
                        $row['capacity_observed_at'] ?? null,
                        $row['capacity_newest_observed_at'] ?? null,
                    ]])
                : [null, null];
            $capacity = new ActivationEvidenceObservation(
                $complete && $pbsCapacityComplete ? $capacityAccepted : null,
                $capacityObservedAt,
                $capacityNewestObservedAt,
            );
            $result[$hex] = new TargetCandidateEvidence(
                new ActivationEvidenceObservation(
                    $inventoryAccepted && $complete,
                    $inventoryObservedAt,
                    $inventoryNewestObservedAt,
                ),
                $inventory,
                $capacity,
            );
        }
        return $result;
    }

    public function policyEvidence(PolicyId $id): PolicyActivationEvidence
    {
        return $this->policyEvidenceBatch([$id])[bin2hex($id->binary())];
    }

    public function policyEvidenceBatch(array $ids): array
    {
        $missing = new ActivationEvidenceObservation(null, null);
        $result = [];
        $binaryIds = [];
        foreach ($ids as $id) {
            $hex = bin2hex($id->binary());
            $result[$hex] = new PolicyActivationEvidence(null, null, $missing, $missing);
            $binaryIds[$hex] = $id->binary();
        }
        if ([] === $binaryIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT policy.id AS policy_id, policy.target_id,
       (SELECT version_major FROM proxmox_capability_snapshots capability
         WHERE capability.connection_id=policy.connection_id AND capability.product='pve'
         ORDER BY capability.last_observed_at DESC, capability.id DESC LIMIT 1) AS pve_major,
       (SELECT last_observed_at FROM proxmox_capability_snapshots capability
         WHERE capability.connection_id=policy.connection_id AND capability.product='pve'
         ORDER BY capability.last_observed_at DESC, capability.id DESC LIMIT 1) AS pve_observed_at,
       target.status AS target_status,
       storage.storage_type, policy.retention_execution_enabled
FROM backup_policies policy
LEFT JOIN backup_targets target
  ON target.id=policy.target_id
 AND target.connection_id=policy.connection_id
 AND target.cluster_id=policy.cluster_id
LEFT JOIN pve_storages storage
  ON storage.id=target.storage_id
 AND storage.connection_id=target.connection_id
 AND storage.cluster_id=target.cluster_id
WHERE policy.id IN (:policy_ids)
SQL, ['policy_ids' => array_values($binaryIds)], ['policy_ids' => ArrayParameterType::BINARY]);
        $targetIds = [];
        foreach ($rows as $row) {
            $targetId = $row['target_id'] ?? null;
            if (is_string($targetId) && 16 === strlen($targetId)) {
                $targetIds[bin2hex($targetId)] = new BackupTargetId($targetId);
            }
        }
        $candidates = $this->candidateEvidenceBatch(array_values($targetIds));
        $executors = $this->executorEvidenceBatch(array_values($targetIds));
        foreach ($rows as $row) {
            $policyId = $this->binaryId($row['policy_id'] ?? null);
            $policyHex = bin2hex($policyId);
            if (!isset($result[$policyHex])) {
                throw new RuntimeException('MariaDB returned activation evidence for an unrequested policy.');
            }
            $targetId = $row['target_id'] ?? null;
            $targetHex = is_string($targetId) && 16 === strlen($targetId) ? bin2hex($targetId) : null;
            $targetEvidence = null === $targetHex
                ? $missing
                : $this->aggregateTargetEvidence(
                    $candidates[$targetHex],
                );
            $targetEnabled = null === $targetHex
                ? null
                : 'enabled' === $this->text($row['target_status'] ?? null);
            $result[$policyHex] = new PolicyActivationEvidence(
                null === ($row['pve_major'] ?? null) ? null : $this->integer($row['pve_major']),
                null === ($row['pve_observed_at'] ?? null) ? null : $this->date($row['pve_observed_at']),
                $targetEvidence,
                null === $targetHex ? $missing : $executors[$targetHex],
                'pbs' === ($row['storage_type'] ?? null),
                $this->boolean($row['retention_execution_enabled'] ?? null),
                $targetEnabled,
            );
        }
        return $result;
    }

    private function aggregateTargetEvidence(TargetCandidateEvidence $evidence): ActivationEvidenceObservation
    {
        $observations = [$evidence->candidate, $evidence->inventory, $evidence->capacity];
        $accepted = true;
        $oldest = null;
        $newest = null;
        foreach ($observations as $observation) {
            if (null === $observation->accepted || null === $observation->observedAt
                || null === $observation->newestObservedAt) {
                return new ActivationEvidenceObservation(null, null);
            }
            $accepted = $accepted && $observation->accepted;
            if (null === $oldest || $observation->observedAt < $oldest) {
                $oldest = $observation->observedAt;
            }
            if (null === $newest || $observation->newestObservedAt > $newest) {
                $newest = $observation->newestObservedAt;
            }
        }
        return new ActivationEvidenceObservation($accepted, $oldest, $newest);
    }

    /**
     * @param list<array{mixed, mixed}> $ranges
     * @return array{?DateTimeImmutable, ?DateTimeImmutable}
     */
    private function dateRange(array $ranges): array
    {
        $oldest = null;
        $newest = null;
        foreach ($ranges as [$oldestValue, $newestValue]) {
            if (null === $oldestValue || null === $newestValue) {
                return [null, null];
            }
            $rangeOldest = $this->date($oldestValue);
            $rangeNewest = $this->date($newestValue);
            if (null === $oldest || $rangeOldest < $oldest) {
                $oldest = $rangeOldest;
            }
            if (null === $newest || $rangeNewest > $newest) {
                $newest = $rangeNewest;
            }
        }
        return [$oldest, $newest];
    }

    private function integer(mixed $value): int { if (!is_int($value) && !is_string($value) || !ctype_digit((string) $value)) throw new RuntimeException('Invalid activation evidence integer.'); return (int) $value; }
    private function boolean(mixed $value): bool { return 1 === $this->integer($value); }
    private function text(mixed $value): string { if (!is_string($value) || '' === $value) throw new RuntimeException('Invalid activation evidence text.'); return $value; }
    private function date(mixed $value): DateTimeImmutable { if (!is_string($value)) throw new RuntimeException('Invalid activation evidence date.'); $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$value,new DateTimeZone('UTC')); if(false===$date) throw new RuntimeException('Invalid activation evidence date.'); return $date; }
    private function decimalGreaterOrEqual(mixed $left, mixed $right): bool { $left=$this->decimal($left); $right=$this->decimal($right); $left=ltrim($left,'0') ?: '0'; $right=ltrim($right,'0') ?: '0'; return strlen($left)>strlen($right)||(strlen($left)===strlen($right)&&$left>=$right); }
    private function decimal(mixed $value): string { if(!is_int($value)&&!is_string($value)||!ctype_digit((string)$value)) throw new RuntimeException('Invalid activation evidence bytes.'); return (string)$value; }
    private function binaryId(mixed $value): string { if (!is_string($value) || 16 !== strlen($value)) throw new RuntimeException('Invalid activation evidence identifier.'); return $value; }
}
