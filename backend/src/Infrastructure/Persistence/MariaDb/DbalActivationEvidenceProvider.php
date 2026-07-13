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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalActivationEvidenceProvider implements TargetCandidateEvidenceProvider, TargetExecutorEvidenceProvider, PolicyActivationEvidenceProvider
{
    public function __construct(private Connection $connection)
    {
    }

    public function executorEvidence(BackupTargetId $id): ActivationEvidenceObservation
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT COUNT(allowed.node_id) AS expected_count, COUNT(evidence.node_id) AS evidence_count,
       MIN(evidence.authorized) AS accepted, MIN(evidence.observed_at) AS observed_at
FROM backup_target_allowed_nodes allowed
LEFT JOIN executor_permission_evidence evidence
  ON evidence.connection_id=allowed.connection_id AND evidence.cluster_id=allowed.cluster_id
 AND evidence.node_id=allowed.node_id
 AND evidence.target_id=allowed.target_id AND evidence.guest_id IS NULL
 AND evidence.storage_id=(SELECT storage_id FROM backup_targets WHERE id=:target_id)
WHERE allowed.target_id=:target_id
SQL, ['target_id' => $id->binary()], ['target_id' => ParameterType::BINARY]);
        if (false === $row || $this->integer($row['expected_count'] ?? null) < 1
            || $this->integer($row['expected_count'] ?? null) !== $this->integer($row['evidence_count'] ?? null)) {
            return new ActivationEvidenceObservation(null, null);
        }
        return new ActivationEvidenceObservation($this->boolean($row['accepted'] ?? null), $this->date($row['observed_at'] ?? null));
    }

    public function candidateEvidence(BackupTargetId $id): TargetCandidateEvidence
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT connection.enabled AS connection_enabled, cluster.inventory_state AS cluster_state,
       storage.supports_backup, storage.disabled AS storage_disabled, storage.inventory_state AS storage_state,
       storage.last_seen_at AS inventory_observed_at, target.minimum_free_bytes,
       COUNT(allowed.node_id) AS allowed_count, COUNT(state.node_id) AS state_count,
       MIN(state.enabled) AS nodes_enabled, MIN(state.active) AS nodes_active,
       MIN(state.available_bytes) AS available_bytes, MIN(state.observed_at) AS capacity_observed_at
FROM backup_targets target
JOIN proxmox_connections connection ON connection.id=target.connection_id
JOIN pve_clusters cluster ON cluster.id=target.cluster_id
JOIN pve_storages storage ON storage.id=target.storage_id
LEFT JOIN backup_target_allowed_nodes allowed ON allowed.target_id=target.id
LEFT JOIN pve_node_storage_state state ON state.connection_id=allowed.connection_id
 AND state.cluster_id=allowed.cluster_id AND state.node_id=allowed.node_id AND state.storage_id=target.storage_id
WHERE target.id=:target_id
GROUP BY target.id
SQL, ['target_id' => $id->binary()], ['target_id' => ParameterType::BINARY]);
        if (false === $row) {
            $missing = new ActivationEvidenceObservation(null, null);
            return new TargetCandidateEvidence($missing, $missing, $missing);
        }
        $inventoryAccepted = $this->boolean($row['connection_enabled'] ?? null)
            && 'active' === $this->text($row['cluster_state'] ?? null)
            && $this->boolean($row['supports_backup'] ?? null)
            && !$this->boolean($row['storage_disabled'] ?? null)
            && 'active' === $this->text($row['storage_state'] ?? null);
        $inventory = new ActivationEvidenceObservation($inventoryAccepted, $this->date($row['inventory_observed_at'] ?? null));
        $allowed = $this->integer($row['allowed_count'] ?? null);
        $complete = $allowed > 0 && $allowed === $this->integer($row['state_count'] ?? null);
        $capacityAccepted = $complete && $this->boolean($row['nodes_enabled'] ?? null) && $this->boolean($row['nodes_active'] ?? null)
            && null !== ($row['available_bytes'] ?? null) && null !== ($row['minimum_free_bytes'] ?? null)
            && $this->decimalGreaterOrEqual($row['available_bytes'], $row['minimum_free_bytes']);
        $capacity = new ActivationEvidenceObservation($complete ? $capacityAccepted : null,
            $complete ? $this->date($row['capacity_observed_at'] ?? null) : null);
        return new TargetCandidateEvidence(
            new ActivationEvidenceObservation($inventoryAccepted && $complete, $this->date($row['inventory_observed_at'] ?? null)),
            $inventory,
            $capacity,
        );
    }

    public function policyEvidence(PolicyId $id): PolicyActivationEvidence
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT policy.target_id,
       (SELECT version_major FROM proxmox_capability_snapshots capability
         WHERE capability.connection_id=policy.connection_id AND capability.product='pve'
         ORDER BY capability.last_observed_at DESC, capability.id DESC LIMIT 1) AS pve_major,
       (SELECT last_observed_at FROM proxmox_capability_snapshots capability
         WHERE capability.connection_id=policy.connection_id AND capability.product='pve'
         ORDER BY capability.last_observed_at DESC, capability.id DESC LIMIT 1) AS pve_observed_at,
       target.status AS target_status, target.updated_at AS target_observed_at
FROM backup_policies policy
LEFT JOIN backup_targets target ON target.id=policy.target_id
WHERE policy.id=:policy_id
SQL, ['policy_id' => $id->binary()], ['policy_id' => ParameterType::BINARY]);
        if (false === $row) {
            $missing = new ActivationEvidenceObservation(null, null);
            return new PolicyActivationEvidence(null, null, $missing, $missing);
        }
        $targetId = $row['target_id'] ?? null;
        $target = null === $targetId
            ? new ActivationEvidenceObservation(null, null)
            : new ActivationEvidenceObservation('enabled' === $this->text($row['target_status'] ?? null), $this->date($row['target_observed_at'] ?? null));
        $executor = is_string($targetId) && 16 === strlen($targetId)
            ? $this->executorEvidence(new BackupTargetId($targetId))
            : new ActivationEvidenceObservation(null, null);
        return new PolicyActivationEvidence(
            null === ($row['pve_major'] ?? null) ? null : $this->integer($row['pve_major']),
            null === ($row['pve_observed_at'] ?? null) ? null : $this->date($row['pve_observed_at']),
            $target,
            $executor,
        );
    }

    private function integer(mixed $value): int { if (!is_int($value) && !is_string($value) || !ctype_digit((string) $value)) throw new RuntimeException('Invalid activation evidence integer.'); return (int) $value; }
    private function boolean(mixed $value): bool { return 1 === $this->integer($value); }
    private function text(mixed $value): string { if (!is_string($value) || '' === $value) throw new RuntimeException('Invalid activation evidence text.'); return $value; }
    private function date(mixed $value): DateTimeImmutable { if (!is_string($value)) throw new RuntimeException('Invalid activation evidence date.'); $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$value,new DateTimeZone('UTC')); if(false===$date) throw new RuntimeException('Invalid activation evidence date.'); return $date; }
    private function decimalGreaterOrEqual(mixed $left, mixed $right): bool { $left=$this->decimal($left); $right=$this->decimal($right); $left=ltrim($left,'0') ?: '0'; $right=ltrim($right,'0') ?: '0'; return strlen($left)>strlen($right)||(strlen($left)===strlen($right)&&$left>=$right); }
    private function decimal(mixed $value): string { if(!is_int($value)&&!is_string($value)||!ctype_digit((string)$value)) throw new RuntimeException('Invalid activation evidence bytes.'); return (string)$value; }
}
