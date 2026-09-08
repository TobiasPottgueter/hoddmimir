<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Configuration\Policy\PolicyCommandRepository;
use App\Application\Configuration\Selection\SelectionCommandRepository;
use App\Application\Configuration\Target\TargetCommandRepository;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Domain\Target\AllowedNodes;
use App\Domain\Target\BackupTarget;
use App\Domain\Target\BackupTargetId;
use App\Domain\Target\ConcurrencyPolicy;
use App\Domain\Target\MinimumFreeBytes;
use App\Domain\Target\PbsTargetMapping;
use App\Domain\Target\TargetRevision;
use App\Domain\Target\TargetStatus;
use App\Domain\Policy\BackupPolicy;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\Compression;
use App\Domain\Policy\FailureNotificationRecipients;
use App\Domain\Policy\PolicyId;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyStatus;
use App\Domain\Policy\PolicyThresholds;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalConfigurationCommandRepository implements TargetCommandRepository, PolicyCommandRepository, SelectionCommandRepository
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection)
    {
    }

    public function find(BackupTargetId $id): ?BackupTarget
    {
        $row = $this->connection->fetchAssociative(
            'SELECT target.*, storage.storage_type FROM backup_targets target JOIN pve_storages storage ON storage.id = target.storage_id WHERE target.id = ?',
            [$id->binary()], [ParameterType::BINARY],
        );
        if (false === $row) {
            return null;
        }
        $nodes = $this->connection->fetchFirstColumn(
            'SELECT node_id FROM backup_target_allowed_nodes WHERE target_id = ? ORDER BY node_id',
            [$id->binary()], [ParameterType::BINARY],
        );
        $nodeIds = [];
        foreach ($nodes as $node) {
            $nodeIds[] = $this->binary($node);
        }
        $minimum = null === $row['minimum_free_bytes'] ? null : new MinimumFreeBytes($this->decimal($row['minimum_free_bytes']));
        $concurrency = null === $row['fixed_parallel_limit'] ? null : new ConcurrencyPolicy($this->integer($row['fixed_parallel_limit']));
        $mapping = null === $row['pbs_connection_id'] ? null : new PbsTargetMapping(
            $this->binary($row['pbs_connection_id']), $this->binary($row['pbs_datastore_id']),
            null === $row['pbs_namespace_id'] ? null : $this->binary($row['pbs_namespace_id']),
        );
        return new BackupTarget($id, new TargetRevision($this->integer($row['revision'])),
            TargetStatus::from($this->text($row['status'])), 'pbs' === $this->text($row['storage_type']),
            $minimum, new AllowedNodes($nodeIds), $concurrency, $mapping, (new BackupDefaultsMapper())->fromRow($row));
    }

    public function findPolicy(PolicyId $id): ?BackupPolicy
    {
        $row = $this->connection->fetchAssociative('SELECT policy.*, target.default_backup_mode, target.default_compression, target.default_legacy_maxfiles, target.default_keep_all, target.default_keep_last, target.default_keep_hourly, target.default_keep_daily, target.default_keep_weekly, target.default_keep_monthly, target.default_keep_yearly FROM backup_policies policy LEFT JOIN backup_targets target ON target.id = policy.target_id WHERE policy.id = ?', [$id->binary()], [ParameterType::BINARY]);
        if (false === $row) {
            return null;
        }
        $targetId = null === $row['target_id'] ? null : new BackupTargetId($this->binary($row['target_id']));
        $maximumAge = null === $row['maximum_age_seconds'] ? null : $this->integer($row['maximum_age_seconds']);
        $bytes = null === $row['bytes_written_threshold'] ? null : $this->decimal($row['bytes_written_threshold']);
        $cooldown = null === $row['cooldown_seconds'] ? null : $this->integer($row['cooldown_seconds']);
        $thresholds = null === $maximumAge && null === $bytes ? null : new PolicyThresholds($maximumAge, $bytes, $cooldown);

        return BackupPolicy::rehydrate(
            $id,
            new PolicyRevision($this->integer($row['revision'])),
            PolicyStatus::from($this->text($row['status'])),
            $targetId,
            null === $row['backup_mode'] ? null : BackupMode::from($this->text($row['backup_mode'])),
            null === $row['compression'] ? null : Compression::from($this->text($row['compression'])),
            $this->retention($row),
            null === $row['policy_priority'] ? null : new PolicyPriority($this->integer($row['policy_priority'])),
            $thresholds,
            null === $row['schedule'] ? null : Schedule::from($this->text($row['schedule'])),
            $this->storedFailureRecipients($row['failure_notification_recipients_json'] ?? null),
            (new BackupDefaultsMapper())->fromRow($row),
        );
    }

    public function execute(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult
    {
        try {
            return $this->connection->transactional(function () use ($command, $principal): ConfigurationCommandResult {
                $existing = $this->idempotency($command, $principal, true);
                if (null !== $existing) {
                    if (ConfigurationCommandStatus::Conflict === $existing->status) {
                        $this->audit($command, $principal, $existing);
                    }
                    return $existing;
                }
                $result = match ($command->type) {
                    ConfigurationCommandType::TargetCreate, ConfigurationCommandType::TargetUpdate,
                    ConfigurationCommandType::TargetEnable, ConfigurationCommandType::TargetDisable => $this->target($command),
                    ConfigurationCommandType::PolicyCreate, ConfigurationCommandType::PolicyUpdate,
                    ConfigurationCommandType::PolicyEnable, ConfigurationCommandType::PolicyDisable => $this->policy($command),
                    ConfigurationCommandType::SelectionUpsert, ConfigurationCommandType::SelectionDisable,
                    ConfigurationCommandType::GuestOverrideUpsert, ConfigurationCommandType::GuestOverrideDisable => $this->selection($command),
                    default => throw new \InvalidArgumentException('An unrelated command reached configuration persistence.'),
                };
                $this->persistIdempotency($command, $principal, $result);
                $this->audit($command, $principal, $result);
                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            $result = $this->idempotency($command, $principal, false);
            if (null === $result) {
                throw new RuntimeException('The idempotency race could not be resolved.');
            }
            return $result;
        }
    }

    public function record(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): ConfigurationCommandResult
    {
        return $this->connection->transactional(function () use ($command, $principal, $result): ConfigurationCommandResult {
            $existing = $this->idempotency($command, $principal, true);
            if (null !== $existing) {
                if (ConfigurationCommandStatus::Conflict === $existing->status) {
                    $this->audit($command, $principal, $existing);
                }
                return $existing;
            }
            $this->persistIdempotency($command, $principal, $result);
            $this->audit($command, $principal, $result);
            return $result;
        });
    }

    private function target(ConfigurationCommand $command): ConfigurationCommandResult
    {
        if (ConfigurationCommandType::TargetCreate === $command->type) {
            if (0 !== $command->expectedRevision) {
                return new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, 0);
            }
            $now = $this->now();
            $this->connection->insert('backup_targets', [
                'id' => $command->subjectId,
                'connection_id' => $this->payloadBinary($command, 'connectionId'),
                'cluster_id' => $this->payloadBinary($command, 'clusterId'),
                'storage_id' => $this->payloadBinary($command, 'storageId'),
                'display_name' => $this->payloadString($command, 'displayName'),
                'status' => 'disabled', 'revision' => 1,
                'minimum_free_bytes' => $this->payloadNullableDecimal($command, 'minimumFreeBytes'),
                'fixed_parallel_limit' => $this->payloadNullableInt($command, 'fixedParallelLimit'),
                'pbs_connection_id' => $this->payloadNullableBinary($command, 'pbsConnectionId'),
                'pbs_datastore_id' => $this->payloadNullableBinary($command, 'pbsDatastoreId'),
                'pbs_namespace_id' => $this->payloadNullableBinary($command, 'pbsNamespaceId'),
                'created_at' => $now, 'updated_at' => $now, 'disabled_at' => $now,
            ] + (new BackupDefaultsMapper())->payloadData($command->payload), $this->targetTypes());
            $this->replaceAllowedNodes($command, $this->payloadBinary($command, 'connectionId'), $this->payloadBinary($command, 'clusterId'));
            return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, 1);
        }

        $row = $this->lockRevision('backup_targets', $command->subjectId);
        if (null === $row) {
            return ConfigurationCommandResult::blocked('target_missing');
        }
        $revision = $this->integer($row['revision']);
        if ($revision !== $command->expectedRevision) {
            return new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, $revision);
        }
        $next = $revision + 1;
        if (ConfigurationCommandType::TargetUpdate === $command->type) {
            if (!hash_equals($this->binary($row['connection_id']), $this->payloadBinary($command, 'connectionId'))
                || !hash_equals($this->binary($row['cluster_id']), $this->payloadBinary($command, 'clusterId'))
                || !hash_equals($this->binary($row['storage_id']), $this->payloadBinary($command, 'storageId'))) {
                return ConfigurationCommandResult::blocked('immutable_context_mismatch');
            }
            $defaultsData = [];
            foreach (BackupDefaultsMapper::FIELDS as $field => $column) {
                $defaultsData[$field] = array_key_exists($field, $command->payload) ? $command->payload[$field] : ($row[$column] ?? null);
            }
            $defaultsData = (new BackupDefaultsMapper())->payloadData($defaultsData);
            $defaultsChanged = (new BackupDefaultsMapper())->fromRow($row) != (new BackupDefaultsMapper())->fromRow($defaultsData);
            if ($defaultsChanged && false !== $this->connection->fetchOne(
                "SELECT id FROM backup_policies WHERE target_id = ? AND status = 'enabled' LIMIT 1 FOR UPDATE",
                [$command->subjectId], [ParameterType::BINARY],
            )) {
                return ConfigurationCommandResult::blocked('disable_target_policies_before_changing_defaults');
            }
            $this->connection->update('backup_targets', [
                'display_name' => $this->payloadString($command, 'displayName'),
                'minimum_free_bytes' => $this->payloadNullableDecimal($command, 'minimumFreeBytes'),
                'fixed_parallel_limit' => $this->payloadNullableInt($command, 'fixedParallelLimit'),
                'pbs_connection_id' => $this->payloadNullableBinary($command, 'pbsConnectionId'),
                'pbs_datastore_id' => $this->payloadNullableBinary($command, 'pbsDatastoreId'),
                'pbs_namespace_id' => $this->payloadNullableBinary($command, 'pbsNamespaceId'),
                'revision' => $next, 'updated_at' => $this->now(),
            ] + $defaultsData, ['id' => $command->subjectId], $this->targetUpdateTypes() + ['id' => ParameterType::BINARY]);
            $this->replaceAllowedNodes($command, $this->binary($row['connection_id']), $this->binary($row['cluster_id']));
        } else {
            $enabled = ConfigurationCommandType::TargetEnable === $command->type;
            $now = $this->now();
            $this->connection->update('backup_targets', [
                'status' => $enabled ? 'enabled' : 'disabled', 'revision' => $next,
                'updated_at' => $now, 'disabled_at' => $enabled ? null : $now,
            ], ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        }
        return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, $next);
    }

    private function policy(ConfigurationCommand $command): ConfigurationCommandResult
    {
        if (ConfigurationCommandType::PolicyCreate === $command->type) {
            if (0 !== $command->expectedRevision) {
                return new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, 0);
            }
            $now = $this->now();
            $data = $this->policyData($command);
            $blockers = $this->policyRetentionBlockers($data);
            if ([] !== $blockers) {
                return ConfigurationCommandResult::blocked(...$blockers);
            }
            $data += ['id' => $command->subjectId, 'status' => 'draft', 'revision' => 1,
                'created_at' => $now, 'updated_at' => $now, 'disabled_at' => null];
            $this->connection->insert('backup_policies', $data, $this->policyTypes());
            return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, 1);
        }
        $row = $this->lockRevision('backup_policies', $command->subjectId);
        if (null === $row) {
            return ConfigurationCommandResult::blocked('policy_missing');
        }
        $revision = $this->integer($row['revision']);
        if ($revision !== $command->expectedRevision) {
            return new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, $revision);
        }
        $next = $revision + 1;
        if (ConfigurationCommandType::PolicyUpdate === $command->type) {
            if (!hash_equals($this->binary($row['connection_id']), $this->payloadBinary($command, 'connectionId'))
                || !hash_equals($this->binary($row['cluster_id']), $this->payloadBinary($command, 'clusterId'))) {
                return ConfigurationCommandResult::blocked('immutable_context_mismatch');
            }
            $data = $this->policyData($command);
            $data['id'] = $command->subjectId;
            $blockers = $this->policyRetentionBlockers($data);
            if ('enabled' === ($row['status'] ?? null)) {
                array_push($blockers, ...$this->policyNotificationBlockers($data), ...$this->policyConfigurationBlockers($data));
            }
            if ([] !== $blockers) {
                return ConfigurationCommandResult::blocked(...$blockers);
            }
            // The policy id is guard context only. Keeping it in the DBAL data
            // map would generate `SET id = ?` and exceed the WebApp role's
            // intentionally immutable-primary-key update grant.
            unset($data['id'], $data['connection_id'], $data['cluster_id']);
            $data += ['revision' => $next, 'updated_at' => $this->now()];
            $this->connection->update('backup_policies', $data, ['id' => $command->subjectId], $this->policyTypes() + ['id' => ParameterType::BINARY]);
        } else {
            $enabled = ConfigurationCommandType::PolicyEnable === $command->type;
            if ($enabled) {
                $blockers = $this->policyRetentionBlockers($row);
                array_push($blockers, ...$this->policyNotificationBlockers($row), ...$this->policyConfigurationBlockers($row));
                if ([] !== $blockers) {
                    return ConfigurationCommandResult::blocked(...$blockers);
                }
            }
            $now = $this->now();
            $this->connection->update('backup_policies', ['status' => $enabled ? 'enabled' : 'disabled',
                'revision' => $next, 'updated_at' => $now, 'disabled_at' => $enabled ? null : $now],
                ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        }
        return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, $next);
    }

    /** @param array<string, mixed> $row
     *  @return list<string>
     */
    private function policyConfigurationBlockers(array $row): array
    {
        $target = $this->connection->fetchAssociative('SELECT * FROM backup_targets WHERE id = ? FOR UPDATE',
            [$row['target_id'] ?? null], [ParameterType::BINARY]);
        $defaults = (new BackupDefaultsMapper())->fromRow(false === $target ? [] : $target);
        $blockers = [];
        if (null === ($row['backup_mode'] ?? $defaults->mode)) $blockers[] = 'mode_unconfigured';
        if (null === ($row['compression'] ?? $defaults->compression)) $blockers[] = 'compression_unconfigured';
        if (null === ($this->retention($row) ?? $defaults->retention)) $blockers[] = 'retention_unconfigured';
        return $blockers;
    }

    /** @param array<string, mixed> $policyData
     *  @return list<string>
     */
    private function policyRetentionBlockers(array $policyData, bool $includeExistingGuestOverrides = true): array
    {
        $targetId = $policyData['target_id'] ?? null;
        $connectionId = $policyData['connection_id'] ?? null;
        $clusterId = $policyData['cluster_id'] ?? null;
        if (!is_string($targetId) || 16 !== strlen($targetId)
            || !is_string($connectionId) || 16 !== strlen($connectionId)
            || !is_string($clusterId) || 16 !== strlen($clusterId)) {
            return [];
        }

        $target = $this->connection->fetchAssociative(<<<'SQL'
SELECT target.*, storage.storage_type,
       (SELECT capability.version_major
          FROM proxmox_capability_snapshots capability
         WHERE capability.connection_id = target.connection_id AND capability.product = 'pve'
         ORDER BY capability.last_observed_at DESC, capability.id DESC
         LIMIT 1) AS pve_major
FROM backup_targets target
JOIN pve_storages storage
  ON storage.connection_id = target.connection_id
 AND storage.cluster_id = target.cluster_id
 AND storage.id = target.storage_id
WHERE target.id = :target_id
  AND target.connection_id = :connection_id
  AND target.cluster_id = :cluster_id
FOR UPDATE
SQL, [
            'target_id' => $targetId,
            'connection_id' => $connectionId,
            'cluster_id' => $clusterId,
        ], [
            'target_id' => ParameterType::BINARY,
            'connection_id' => ParameterType::BINARY,
            'cluster_id' => ParameterType::BINARY,
        ]);
        if (false === $target) {
            return [];
        }

        $blockers = [];
        if ('pbs' === ($target['storage_type'] ?? null)
            && 1 === $this->integer($policyData['retention_execution_enabled'] ?? null)) {
            $blockers[] = 'retention_execution_forbidden_for_pbs_target';
        }
        $pveMajor = $target['pve_major'] ?? null;
        $legacyRetentionConfigured = null !== ($this->retention($policyData) ?? (new BackupDefaultsMapper())->fromRow($target)->retention)?->legacyMaxFiles;
        $policyId = $policyData['id'] ?? null;
        if ($includeExistingGuestOverrides && !$legacyRetentionConfigured && is_string($policyId) && 16 === strlen($policyId)) {
            $legacyRetentionConfigured = 1 === $this->integer($this->connection->fetchOne(
                "SELECT EXISTS(SELECT 1 FROM backup_policy_guest_overrides WHERE policy_id = :policy_id AND status = 'active' AND legacy_maxfiles IS NOT NULL)",
                ['policy_id' => $policyId],
                ['policy_id' => ParameterType::BINARY],
            ));
        }
        if ($legacyRetentionConfigured && (is_int($pveMajor) || is_string($pveMajor)) && 9 === (int) $pveMajor) {
            $blockers[] = 'retention_incompatible';
        }

        return $blockers;
    }

    /** @param array<string, mixed> $policyData
     *  @return list<string>
     */
    private function policyNotificationBlockers(array $policyData): array
    {
        return [] === $this->storedFailureRecipients(
            $policyData['failure_notification_recipients_json'] ?? null,
        )->addresses
            ? ['failure_notification_recipients_unconfigured']
            : [];
    }

    private function selection(ConfigurationCommand $command): ConfigurationCommandResult
    {
        $command->boundedEntries('entries');
        $policy = $this->lockRevision('backup_policies', $command->subjectId);
        if (null === $policy) {
            return ConfigurationCommandResult::blocked('policy_missing');
        }
        $revision = $this->integer($policy['revision']);
        if ($revision !== $command->expectedRevision) {
            return new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, $revision);
        }
        /** @var list<mixed> $entries */
        $entries = $command->payload['entries'];
        if (ConfigurationCommandType::GuestOverrideUpsert === $command->type) {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw new RuntimeException('A selection command entry is invalid.');
                }
                $retentionData = $policy;
                $retentionData['legacy_maxfiles'] = $entry['legacyMaxfiles'] ?? null;
                $blockers = $this->policyRetentionBlockers($retentionData, false);
                if ([] !== $blockers) {
                    return ConfigurationCommandResult::blocked(...$blockers);
                }
            }
        }
        $now = $this->now();
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('A selection command entry is invalid.');
            }
            /** @var array<string, mixed> $entry */
            if (ConfigurationCommandType::SelectionDisable === $command->type || ConfigurationCommandType::GuestOverrideDisable === $command->type) {
                $table = ConfigurationCommandType::SelectionDisable === $command->type ? 'backup_policy_assignments' : 'backup_policy_guest_overrides';
                $this->connection->executeStatement("UPDATE {$table} SET status = 'disabled', revision = revision + 1, updated_at = ?, disabled_at = ? WHERE policy_id = ? AND id = ? AND status = 'active'",
                    [$now, $now, $command->subjectId, $this->entryBinary($entry, 'id')], [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY]);
            } elseif (ConfigurationCommandType::SelectionUpsert === $command->type) {
                $this->upsertAssignment($command, $entry, $policy, $now);
            } else {
                $this->upsertGuestOverride($command, $entry, $policy, $now);
            }
        }
        $next = $revision + 1;
        $this->connection->update('backup_policies', ['revision' => $next, 'updated_at' => $now], ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, $next);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $policy
     */
    private function upsertAssignment(ConfigurationCommand $command, array $entry, array $policy, string $now): void
    {
        $id = $this->entryBinary($entry, 'id');
        $exists = false !== $this->connection->fetchOne(
            'SELECT 1 FROM backup_policy_assignments WHERE policy_id = ? AND id = ?',
            [$command->subjectId, $id],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
        if ($exists) {
            $this->connection->executeStatement(
                "UPDATE backup_policy_assignments SET selection_value = ?, status = 'active', revision = revision + 1, updated_at = ?, disabled_at = NULL WHERE policy_id = ? AND id = ?",
                [$this->entryString($entry, 'selectionValue'), $now, $command->subjectId, $id],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY],
            );
            return;
        }
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO backup_policy_assignments
 (id, policy_id, connection_id, cluster_id, scope, subject_connection_id, subject_cluster_id, node_id, guest_id, selection_value, status, revision, created_at, updated_at, disabled_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, ?, ?, NULL)
SQL, [$id, $command->subjectId, $this->binary($policy['connection_id']), $this->binary($policy['cluster_id']),
            $this->entryString($entry, 'scope'), $this->entryNullableBinary($entry, 'subjectConnectionId'), $this->entryNullableBinary($entry, 'subjectClusterId'),
            $this->entryNullableBinary($entry, 'nodeId'), $this->entryNullableBinary($entry, 'guestId'), $this->entryString($entry, 'selectionValue'), $now, $now],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY,
                ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY,
                ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING]);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $policy
     */
    private function upsertGuestOverride(ConfigurationCommand $command, array $entry, array $policy, string $now): void
    {
        $id = $this->entryBinary($entry, 'id');
        $values = [$entry['backupMode'] ?? null, $entry['compression'] ?? null, $entry['legacyMaxfiles'] ?? null,
            $entry['keepAll'] ?? null, $entry['keepLast'] ?? null, $entry['keepHourly'] ?? null, $entry['keepDaily'] ?? null,
            $entry['keepWeekly'] ?? null, $entry['keepMonthly'] ?? null, $entry['keepYearly'] ?? null];
        $valueTypes = [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER,
            ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER,
            ParameterType::INTEGER, ParameterType::INTEGER];
        $exists = false !== $this->connection->fetchOne(
            'SELECT 1 FROM backup_policy_guest_overrides WHERE policy_id = ? AND id = ?',
            [$command->subjectId, $id],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
        if ($exists) {
            $this->connection->executeStatement(
                "UPDATE backup_policy_guest_overrides SET backup_mode=?, compression=?, legacy_maxfiles=?, keep_all=?, keep_last=?, keep_hourly=?, keep_daily=?, keep_weekly=?, keep_monthly=?, keep_yearly=?, status='active', revision=revision+1, updated_at=?, disabled_at=NULL WHERE policy_id=? AND id=?",
                [...$values, $now, $command->subjectId, $id],
                [...$valueTypes, ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY],
            );
            return;
        }
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO backup_policy_guest_overrides
 (id, policy_id, connection_id, cluster_id, guest_id, backup_mode, compression, legacy_maxfiles, keep_all, keep_last, keep_hourly, keep_daily, keep_weekly, keep_monthly, keep_yearly, status, revision, created_at, updated_at, disabled_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, ?, ?, NULL)
SQL, [$id, $command->subjectId, $this->binary($policy['connection_id']), $this->binary($policy['cluster_id']),
            $this->entryBinary($entry, 'guestId'), ...$values, $now, $now],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY,
                ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]);
    }

    /** @return array<string, mixed> */
    private function policyData(ConfigurationCommand $command): array
    {
        return [
            'connection_id' => $this->payloadBinary($command, 'connectionId'), 'cluster_id' => $this->payloadBinary($command, 'clusterId'),
            'target_id' => $this->payloadNullableBinary($command, 'targetId'), 'display_name' => $this->payloadString($command, 'displayName'),
            'policy_priority' => $this->payloadNullableInt($command, 'priority'), 'backup_mode' => $command->payload['backupMode'] ?? null,
            'compression' => $command->payload['compression'] ?? null, 'maximum_age_seconds' => $this->payloadNullableDecimal($command, 'maximumAgeSeconds'),
            'bytes_written_threshold' => $this->payloadNullableDecimal($command, 'bytesWrittenThreshold'), 'cooldown_seconds' => $this->payloadNullableDecimal($command, 'cooldownSeconds'),
            'schedule' => $command->payload['schedule'] ?? null, 'legacy_maxfiles' => $command->payload['legacyMaxfiles'] ?? null,
            'keep_all' => $this->payloadNullableBooleanInt($command, 'keepAll'), 'keep_last' => $command->payload['keepLast'] ?? null,
            'keep_hourly' => $command->payload['keepHourly'] ?? null, 'keep_daily' => $command->payload['keepDaily'] ?? null,
            'keep_weekly' => $command->payload['keepWeekly'] ?? null, 'keep_monthly' => $command->payload['keepMonthly'] ?? null,
            'keep_yearly' => $command->payload['keepYearly'] ?? null, 'retention_execution_enabled' => $this->payloadBooleanInt($command, 'retentionExecutionEnabled'),
            'failure_notification_recipients_json' => json_encode($this->payloadFailureRecipients($command)->addresses, JSON_THROW_ON_ERROR),
        ];
    }

    private function payloadFailureRecipients(ConfigurationCommand $command): FailureNotificationRecipients
    {
        $value = $command->payload['failureNotificationRecipients'] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Failure notification recipients are invalid.');
        }
        foreach ($value as $address) {
            if (!is_string($address)) {
                throw new RuntimeException('Failure notification recipients are invalid.');
            }
        }
        /** @var list<string> $value */
        return new FailureNotificationRecipients($value);
    }

    private function storedFailureRecipients(mixed $value): FailureNotificationRecipients
    {
        if (!is_string($value)) {
            throw new RuntimeException('Stored failure notification recipients are invalid.');
        }
        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Stored failure notification recipients are invalid.', 0, $error);
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('Stored failure notification recipients are invalid.');
        }
        foreach ($decoded as $address) {
            if (!is_string($address)) {
                throw new RuntimeException('Stored failure notification recipients are invalid.');
            }
        }
        /** @var list<string> $decoded */
        return new FailureNotificationRecipients($decoded);
    }

    /** @return array<string, mixed>|null */
    private function lockRevision(string $table, string $id): ?array
    {
        if (!in_array($table, ['backup_targets', 'backup_policies'], true)) {
            throw new RuntimeException('An unsafe configuration table was requested.');
        }
        $row = $this->connection->fetchAssociative("SELECT * FROM {$table} WHERE id = ? FOR UPDATE", [$id], [ParameterType::BINARY]);
        return false === $row ? null : $row;
    }

    private function replaceAllowedNodes(ConfigurationCommand $command, string $connectionId, string $clusterId): void
    {
        $nodes = $command->payload['allowedNodeIds'] ?? [];
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) > 500) {
            throw new RuntimeException('The target allowed-node payload is invalid.');
        }
        $this->connection->delete('backup_target_allowed_nodes', ['target_id' => $command->subjectId], ['target_id' => ParameterType::BINARY]);
        foreach ($nodes as $node) {
            if (!is_string($node) || 16 !== strlen($node)) {
                throw new RuntimeException('The target allowed-node payload is invalid.');
            }
            $this->connection->insert('backup_target_allowed_nodes', ['target_id' => $command->subjectId,
                'connection_id' => $connectionId, 'cluster_id' => $clusterId, 'node_id' => $node, 'created_at' => $this->now()],
                ['target_id' => ParameterType::BINARY, 'connection_id' => ParameterType::BINARY, 'cluster_id' => ParameterType::BINARY, 'node_id' => ParameterType::BINARY]);
        }
    }

    private function idempotency(ConfigurationCommand $command, AuthenticatedPrincipal $principal, bool $lock): ?ConfigurationCommandResult
    {
        $row = $this->connection->fetchAssociative('SELECT payload_hash, result_status, result_revision, blocker_code FROM configuration_command_idempotency WHERE actor_user_id = ? AND idempotency_key = ?'.($lock ? ' FOR UPDATE' : ''),
            [$principal->userId->binary(), $command->idempotencyKey], [ParameterType::BINARY, ParameterType::STRING]);
        if (false === $row) {
            return null;
        }
        $revision = null === $row['result_revision'] ? null : $this->integer($row['result_revision']);
        if (!hash_equals($this->binary($row['payload_hash']), $command->payloadHash)) {
            return new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, $revision ?? 0);
        }
        return match ($this->text($row['result_status'])) {
            'applied' => new ConfigurationCommandResult(ConfigurationCommandStatus::Replayed, $revision),
            'conflict' => new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, $revision),
            'blocked' => ConfigurationCommandResult::blocked($this->text($row['blocker_code'])),
            'denied' => ConfigurationCommandResult::denied(),
            default => throw new RuntimeException('A persisted configuration result is invalid.'),
        };
    }

    private function persistIdempotency(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): void
    {
        $storedStatus = ConfigurationCommandStatus::Replayed === $result->status ? 'applied' : $result->status->value;
        $this->connection->insert('configuration_command_idempotency', [
            'actor_user_id' => $principal->userId->binary(), 'idempotency_key' => $command->idempotencyKey,
            'command_type' => $command->type->value, 'subject_id' => $command->subjectId, 'payload_hash' => $command->payloadHash,
            'result_status' => $storedStatus, 'result_revision' => $result->revision,
            'blocker_code' => $result->blockers[0] ?? null, 'created_at' => $this->now(),
        ], ['actor_user_id' => ParameterType::BINARY, 'subject_id' => ParameterType::BINARY, 'payload_hash' => ParameterType::BINARY]);
    }

    private function audit(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): void
    {
        $this->connection->insert('audit_events', [
            'id' => random_bytes(16), 'occurred_at' => $this->now(), 'actor_user_id' => $principal->userId->binary(),
            'actor_session_id' => $principal->sessionId, 'event_type' => $command->type->auditType()->value,
            'outcome' => ConfigurationCommandStatus::Applied === $result->status ? 'succeeded' : 'denied',
            'subject_type' => $command->type->subjectType(), 'subject_id' => $command->subjectId,
            'reason_code' => $result->blockers[0] ?? (ConfigurationCommandStatus::Conflict === $result->status ? 'revision_conflict' : null),
            'correlation_id' => $command->correlationId,
        ], ['id' => ParameterType::BINARY, 'actor_user_id' => ParameterType::BINARY, 'actor_session_id' => ParameterType::BINARY, 'subject_id' => ParameterType::BINARY, 'correlation_id' => ParameterType::BINARY]);
    }

    /** @param array<string, mixed> $entry */
    private function entryBinary(array $entry, string $key): string { $v = $entry[$key] ?? null; if (!is_string($v) || 16 !== strlen($v)) throw new RuntimeException('A command identifier is invalid.'); return $v; }
    /** @param array<string, mixed> $entry */
    private function entryNullableBinary(array $entry, string $key): ?string { $v = $entry[$key] ?? null; if (null === $v) return null; if (!is_string($v) || 16 !== strlen($v)) throw new RuntimeException('A command identifier is invalid.'); return $v; }
    /** @param array<string, mixed> $entry */
    private function entryString(array $entry, string $key): string { $v = $entry[$key] ?? null; if (!is_string($v)) throw new RuntimeException('A command value is invalid.'); return $v; }
    private function payloadBinary(ConfigurationCommand $c, string $key): string { $v = $c->payload[$key] ?? null; if (!is_string($v) || 16 !== strlen($v)) throw new RuntimeException('A command identifier is invalid.'); return $v; }
    private function payloadNullableBinary(ConfigurationCommand $c, string $key): ?string { $v = $c->payload[$key] ?? null; if (null === $v) return null; if (!is_string($v) || 16 !== strlen($v)) throw new RuntimeException('A command identifier is invalid.'); return $v; }
    private function payloadString(ConfigurationCommand $c, string $key): string { $v = $c->payload[$key] ?? null; if (!is_string($v)) throw new RuntimeException('A command value is invalid.'); return $v; }
    private function payloadNullableDecimal(ConfigurationCommand $c, string $key): ?string { $v = $c->payload[$key] ?? null; if (null === $v) return null; if (!is_string($v) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $v)) throw new RuntimeException('A command decimal is invalid.'); return $v; }
    private function payloadNullableInt(ConfigurationCommand $c, string $key): ?int { $v = $c->payload[$key] ?? null; if (null === $v) return null; if (!is_int($v)) throw new RuntimeException('A command integer is invalid.'); return $v; }
    private function payloadBooleanInt(ConfigurationCommand $c, string $key): int { $v = $c->payload[$key] ?? false; if (!is_bool($v)) throw new RuntimeException('A command boolean is invalid.'); return (int) $v; }
    private function payloadNullableBooleanInt(ConfigurationCommand $c, string $key): ?int { $v = $c->payload[$key] ?? null; if (null === $v) return null; if (!is_bool($v)) throw new RuntimeException('A command boolean is invalid.'); return (int) $v; }
    private function binary(mixed $v): string { if (!is_string($v)) throw new RuntimeException('Persisted binary data is invalid.'); return $v; }
    private function text(mixed $v): string { if (!is_string($v)) throw new RuntimeException('Persisted text is invalid.'); return $v; }
    private function integer(mixed $v): int { if (!is_int($v) && !is_string($v)) throw new RuntimeException('Persisted integer is invalid.'); return (int) $v; }
    private function decimal(mixed $v): string { if (!is_int($v) && !is_string($v)) throw new RuntimeException('Persisted decimal is invalid.'); return (string) $v; }
    private function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(self::DATE_FORMAT); }
    /** @param array<string, mixed> $row */
    private function retention(array $row): ?RetentionPolicy
    {
        if (null !== $row['legacy_maxfiles']) {
            return RetentionPolicy::legacyMaxFiles($this->integer($row['legacy_maxfiles']));
        }
        $keys = ['keep_all', 'keep_last', 'keep_hourly', 'keep_daily', 'keep_weekly', 'keep_monthly', 'keep_yearly'];
        if ([] === array_filter($keys, static fn (string $key): bool => null !== $row[$key])) {
            return null;
        }
        return RetentionPolicy::prune(
            null === $row['keep_all'] ? null : 1 === $this->integer($row['keep_all']),
            ...array_map(fn (string $key): ?int => null === $row[$key] ? null : $this->integer($row[$key]), array_slice($keys, 1)),
        );
    }
    /** @return array<string, ParameterType> */
    private function targetTypes(): array { return ['id'=>ParameterType::BINARY,'connection_id'=>ParameterType::BINARY,'cluster_id'=>ParameterType::BINARY,'storage_id'=>ParameterType::BINARY,'pbs_connection_id'=>ParameterType::BINARY,'pbs_datastore_id'=>ParameterType::BINARY,'pbs_namespace_id'=>ParameterType::BINARY]; }
    /** @return array<string, ParameterType> */
    private function targetUpdateTypes(): array { return ['pbs_connection_id'=>ParameterType::BINARY,'pbs_datastore_id'=>ParameterType::BINARY,'pbs_namespace_id'=>ParameterType::BINARY]; }
    /** @return array<string, ParameterType> */
    private function policyTypes(): array { return ['id'=>ParameterType::BINARY,'connection_id'=>ParameterType::BINARY,'cluster_id'=>ParameterType::BINARY,'target_id'=>ParameterType::BINARY]; }
}
