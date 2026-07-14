<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use App\Infrastructure\Persistence\MariaDb\DbalConfigurationCommandRepository;

final class ConfigurationCommandRepositoryTest extends DatabaseTestCase
{
    private const string USER = 'uuuuuuuuuuuuuuuu';
    private const string TARGET = 'tttttttttttttttt';
    private const string POLICY = 'pppppppppppppppp';
    private const string ASSIGNMENT = 'aaaaaaaaaaaaaaaa';
    private const string CONNECTION = 'cccccccccccccccc';
    private const string CLUSTER = 'kkkkkkkkkkkkkkkk';
    private const string STORAGE = 'ssssssssssssssss';
    private const string NODE = 'nnnnnnnnnnnnnnnn';
    private const string GUEST = 'gggggggggggggggg';
    private const string OVERRIDE = 'oooooooooooooooo';

    public function testTargetCreateReplayRevisionConflictAndAuditAreAtomic(): void
    {
        $this->seedContext();
        $repository = new DbalConfigurationCommandRepository($this->connection());
        $sessionId = $this->seedWebSession(self::USER);
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('admin'), [Permission::BackupConfigurationManage], $sessionId);
        $payload = [
            'connectionId' => self::CONNECTION, 'clusterId' => self::CLUSTER, 'storageId' => self::STORAGE,
            'displayName' => 'Primary', 'minimumFreeBytes' => '1024', 'fixedParallelLimit' => 2,
            'pbsConnectionId' => null, 'pbsDatastoreId' => null, 'pbsNamespaceId' => null,
            'allowedNodeIds' => [self::NODE],
        ];
        $command = new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::TARGET, 0, 'target-create', str_repeat('r', 16), $payload);
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute($command, $principal)->status);
        self::assertSame(ConfigurationCommandStatus::Replayed, $repository->execute($command, $principal)->status);
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM backup_targets WHERE id = ?', [self::TARGET]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_events WHERE subject_id = ?', [self::TARGET]));
        self::assertSame($sessionId, $this->connection()->fetchOne('SELECT actor_session_id FROM audit_events WHERE subject_id = ?', [self::TARGET]));

        $changed = new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::TARGET, 0, 'target-create', str_repeat('r', 16),
            array_replace($payload, ['displayName' => 'Changed']));
        self::assertSame(ConfigurationCommandStatus::Conflict, $repository->execute($changed, $principal)->status);

        $stale = new ConfigurationCommand(ConfigurationCommandType::TargetUpdate, self::TARGET, 99, 'target-stale', str_repeat('q', 16), $payload);
        self::assertSame(ConfigurationCommandStatus::Conflict, $repository->execute($stale, $principal)->status);
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM configuration_command_idempotency WHERE actor_user_id = ?', [self::USER]));
        self::assertSame(3, $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_events WHERE subject_id = ?', [self::TARGET]));
    }

    public function testDeniedAndBlockedOutcomesAreIdempotentAndAuditedWithoutMutation(): void
    {
        $this->seedContext();
        $repository = new DbalConfigurationCommandRepository($this->connection());
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('admin'), []);
        $command = new ConfigurationCommand(ConfigurationCommandType::TargetEnable, self::TARGET, 1, 'target-denied', str_repeat('d', 16));
        self::assertSame(ConfigurationCommandStatus::Denied, $repository->record($command, $principal, \App\Application\Configuration\ConfigurationCommandResult::denied())->status);
        self::assertSame(ConfigurationCommandStatus::Denied, $repository->record($command, $principal, \App\Application\Configuration\ConfigurationCommandResult::denied())->status);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_events WHERE reason_code = ?', ['permission_denied']));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM backup_targets'));
    }

    public function testPolicyCreatePersistsFalseKeepAllAsInteger(): void
    {
        $this->seedContext();
        $repository = new DbalConfigurationCommandRepository($this->connection());
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('admin'), [Permission::BackupConfigurationManage]);
        $target = new ConfigurationCommand(ConfigurationCommandType::TargetCreate, self::TARGET, 0, 'target-for-policy', str_repeat('t', 16), [
            'connectionId' => self::CONNECTION, 'clusterId' => self::CLUSTER, 'storageId' => self::STORAGE,
            'displayName' => 'Primary', 'minimumFreeBytes' => null, 'fixedParallelLimit' => null,
            'pbsConnectionId' => null, 'pbsDatastoreId' => null, 'pbsNamespaceId' => null,
            'allowedNodeIds' => [self::NODE],
        ]);
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute($target, $principal)->status);

        $policy = new ConfigurationCommand(ConfigurationCommandType::PolicyCreate, self::POLICY, 0, 'policy-create', str_repeat('p', 16), [
            'connectionId' => self::CONNECTION, 'clusterId' => self::CLUSTER, 'targetId' => self::TARGET,
            'displayName' => 'Nightly', 'priority' => 350, 'backupMode' => 'snapshot', 'compression' => 'zstd',
            'maximumAgeSeconds' => '86400', 'bytesWrittenThreshold' => null, 'cooldownSeconds' => null,
            'schedule' => 'collector_cycle', 'legacyMaxfiles' => null, 'keepAll' => false, 'keepLast' => 5,
            'keepHourly' => null, 'keepDaily' => null, 'keepWeekly' => null, 'keepMonthly' => null, 'keepYearly' => null,
            'retentionExecutionEnabled' => false,
            'failureNotificationRecipients' => ['platform@example.test', 'backup@example.test'],
        ]);

        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute($policy, $principal)->status);
        self::assertSame(0, $this->connection()->fetchOne('SELECT keep_all FROM backup_policies WHERE id = ?', [self::POLICY]));
        self::assertSame('["backup@example.test","platform@example.test"]', $this->connection()->fetchOne('SELECT failure_notification_recipients_json FROM backup_policies WHERE id = ?', [self::POLICY]));
        self::assertSame(['backup@example.test', 'platform@example.test'], $repository->findPolicy(new \App\Domain\Policy\PolicyId(self::POLICY))?->failureNotificationRecipients->addresses);

        $selection = new ConfigurationCommand(ConfigurationCommandType::SelectionUpsert, self::POLICY, 1, 'selection-upsert', str_repeat('s', 16), [
            'entries' => [[
                'id' => self::ASSIGNMENT, 'scope' => 'node', 'subjectConnectionId' => self::CONNECTION,
                'subjectClusterId' => self::CLUSTER, 'nodeId' => self::NODE, 'selectionValue' => 'include',
            ]],
        ]);
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute($selection, $principal)->status);
        self::assertSame('node', $this->connection()->fetchOne('SELECT scope FROM backup_policy_assignments WHERE id = ?', [self::ASSIGNMENT]));
    }

    public function testPolicyWritesAndEnableFailClosedForPbsStorageWithoutMapping(): void
    {
        $this->seedContext();
        $repository = new DbalConfigurationCommandRepository($this->connection());
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('admin'), [Permission::BackupConfigurationManage]);
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::TargetCreate,
            self::TARGET,
            0,
            'pbs-retention-target',
            random_bytes(16),
            $this->targetPayload('PBS target without mapping'),
        ), $principal)->status);
        $this->connection()->update('pve_storages', ['storage_type' => 'pbs'], ['id' => self::STORAGE]);

        $blockedCreate = $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyCreate,
            self::POLICY,
            0,
            'pbs-retention-create',
            random_bytes(16),
            array_replace($this->policyPayload('PBS policy'), ['retentionExecutionEnabled' => true]),
        ), $principal);
        self::assertSame(ConfigurationCommandStatus::Blocked, $blockedCreate->status);
        self::assertSame(['retention_execution_forbidden_for_pbs_target'], $blockedCreate->blockers);
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM backup_policies WHERE id = ?', [self::POLICY]));

        $this->connection()->update('pve_storages', ['storage_type' => 'dir'], ['id' => self::STORAGE]);
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyCreate,
            self::POLICY,
            0,
            'pbs-retention-safe-create',
            random_bytes(16),
            $this->policyPayload('Safe policy'),
        ), $principal)->status);
        $this->connection()->update('pve_storages', ['storage_type' => 'pbs'], ['id' => self::STORAGE]);

        $blockedUpdate = $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyUpdate,
            self::POLICY,
            1,
            'pbs-retention-update',
            random_bytes(16),
            array_replace($this->policyPayload('Unsafe update'), ['retentionExecutionEnabled' => true]),
        ), $principal);
        self::assertSame(ConfigurationCommandStatus::Blocked, $blockedUpdate->status);
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM backup_policies WHERE id = ?', [self::POLICY]));

        $this->connection()->update('backup_policies', ['retention_execution_enabled' => 1], ['id' => self::POLICY]);
        $activationEvidence = (new \App\Infrastructure\Persistence\MariaDb\DbalActivationEvidenceProvider($this->connection()))
            ->policyEvidence(new \App\Domain\Policy\PolicyId(self::POLICY));
        self::assertTrue($activationEvidence->pbsTarget);
        self::assertTrue($activationEvidence->retentionExecutionEnabled);
        $blockedEnable = $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyEnable,
            self::POLICY,
            1,
            'pbs-retention-enable',
            random_bytes(16),
        ), $principal);
        self::assertSame(ConfigurationCommandStatus::Blocked, $blockedEnable->status);
        self::assertSame(['retention_execution_forbidden_for_pbs_target'], $blockedEnable->blockers);
        self::assertSame('draft', $this->connection()->fetchOne('SELECT status FROM backup_policies WHERE id = ?', [self::POLICY]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM backup_policies WHERE id = ?', [self::POLICY]));
    }

    public function testPveNinePolicyWritesRejectLegacyMaxfilesWithoutChangingRevision(): void
    {
        $this->seedContext();
        $this->seedPveCapability(9);
        $repository = new DbalConfigurationCommandRepository($this->connection());
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('admin'), [Permission::BackupConfigurationManage]);
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::TargetCreate,
            self::TARGET,
            0,
            'pve9-retention-target',
            random_bytes(16),
            $this->targetPayload('PVE 9 target'),
        ), $principal)->status);

        $legacyPayload = array_replace($this->policyPayload('Legacy'), [
            'legacyMaxfiles' => 7,
            'keepAll' => null,
            'keepLast' => null,
        ]);
        $blockedCreate = $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyCreate,
            self::POLICY,
            0,
            'pve9-retention-create',
            random_bytes(16),
            $legacyPayload,
        ), $principal);
        self::assertSame(['retention_incompatible'], $blockedCreate->blockers);
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM backup_policies WHERE id = ?', [self::POLICY]));

        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyCreate,
            self::POLICY,
            0,
            'pve9-retention-safe-create',
            random_bytes(16),
            $this->policyPayload('Safe'),
        ), $principal)->status);
        $blockedOverride = $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::GuestOverrideUpsert,
            self::POLICY,
            1,
            'pve9-retention-override',
            random_bytes(16),
            ['entries' => [[
                'id' => self::OVERRIDE,
                'guestId' => self::GUEST,
                'backupMode' => 'snapshot',
                'compression' => 'zstd',
                'legacyMaxfiles' => 2,
                'keepAll' => null,
                'keepLast' => null,
                'keepHourly' => null,
                'keepDaily' => null,
                'keepWeekly' => null,
                'keepMonthly' => null,
                'keepYearly' => null,
            ]]],
        ), $principal);
        self::assertSame(['retention_incompatible'], $blockedOverride->blockers);
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM backup_policy_guest_overrides WHERE policy_id = ?', [self::POLICY]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM backup_policies WHERE id = ?', [self::POLICY]));

        $blockedUpdate = $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyUpdate,
            self::POLICY,
            1,
            'pve9-retention-update',
            random_bytes(16),
            $legacyPayload,
        ), $principal);
        self::assertSame(['retention_incompatible'], $blockedUpdate->blockers);
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM backup_policies WHERE id = ?', [self::POLICY]));

        $now = '2026-07-12 12:00:00.000000';
        $this->connection()->insert('backup_policy_guest_overrides', [
            'id' => self::OVERRIDE,
            'policy_id' => self::POLICY,
            'connection_id' => self::CONNECTION,
            'cluster_id' => self::CLUSTER,
            'guest_id' => self::GUEST,
            'legacy_maxfiles' => 2,
            'status' => 'active',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $blockedEnable = $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyEnable,
            self::POLICY,
            1,
            'pve9-retention-enable',
            random_bytes(16),
        ), $principal);
        self::assertSame(['retention_incompatible'], $blockedEnable->blockers);
        self::assertSame('draft', $this->connection()->fetchOne('SELECT status FROM backup_policies WHERE id = ?', [self::POLICY]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM backup_policies WHERE id = ?', [self::POLICY]));
    }

    public function testCompleteTargetPolicySelectionAndGuestOverrideLifecycle(): void
    {
        $this->seedContext();
        $repository = new DbalConfigurationCommandRepository($this->connection());
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('admin'), [Permission::BackupConfigurationManage]);

        self::assertNull($repository->find(new \App\Domain\Target\BackupTargetId(str_repeat('m', 16))));
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::TargetCreate, self::TARGET, 0, 'lifecycle-target-create', random_bytes(16), $this->targetPayload('Primary'),
        ), $principal)->status);
        $target = $repository->find(new \App\Domain\Target\BackupTargetId(self::TARGET));
        self::assertNotNull($target);
        self::assertSame(1, $target->revision->value);
        self::assertSame([self::NODE], $target->allowedNodes->ids);

        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::TargetUpdate, self::TARGET, 1, 'lifecycle-target-update', random_bytes(16), $this->targetPayload('Updated'),
        ), $principal)->status);
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::TargetEnable, self::TARGET, 2, 'lifecycle-target-enable', random_bytes(16),
        ), $principal)->status);
        self::assertTrue($repository->find(new \App\Domain\Target\BackupTargetId(self::TARGET))?->status->executable());
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::TargetDisable, self::TARGET, 3, 'lifecycle-target-disable', random_bytes(16),
        ), $principal)->status);
        self::assertSame(ConfigurationCommandStatus::Blocked, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::TargetUpdate, str_repeat('m', 16), 1, 'lifecycle-target-missing', random_bytes(16), $this->targetPayload('Missing'),
        ), $principal)->status);
        self::assertSame(ConfigurationCommandStatus::Blocked, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::TargetUpdate, self::TARGET, 4, 'lifecycle-target-context', random_bytes(16),
            array_replace($this->targetPayload('Mismatch'), ['connectionId' => str_repeat('z', 16)]),
        ), $principal)->status);

        self::assertNull($repository->findPolicy(new \App\Domain\Policy\PolicyId(str_repeat('m', 16))));
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyCreate, self::POLICY, 0, 'lifecycle-policy-create', random_bytes(16), $this->policyPayload('Nightly'),
        ), $principal)->status);
        $policy = $repository->findPolicy(new \App\Domain\Policy\PolicyId(self::POLICY));
        self::assertNotNull($policy);
        self::assertSame(1, $policy->revision->value);
        self::assertSame(3, $policy->retention?->keepLast);

        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyUpdate, self::POLICY, 1, 'lifecycle-policy-update', random_bytes(16), $this->policyPayload('Updated'),
        ), $principal)->status);
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyEnable, self::POLICY, 2, 'lifecycle-policy-enable', random_bytes(16),
        ), $principal)->status);
        self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyDisable, self::POLICY, 3, 'lifecycle-policy-disable', random_bytes(16),
        ), $principal)->status);
        self::assertSame(ConfigurationCommandStatus::Blocked, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyUpdate, str_repeat('m', 16), 1, 'lifecycle-policy-missing', random_bytes(16), $this->policyPayload('Missing'),
        ), $principal)->status);
        self::assertSame(ConfigurationCommandStatus::Blocked, $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::PolicyUpdate, self::POLICY, 4, 'lifecycle-policy-context', random_bytes(16),
            array_replace($this->policyPayload('Mismatch'), ['clusterId' => str_repeat('z', 16)]),
        ), $principal)->status);

        $entry = ['id' => self::ASSIGNMENT, 'scope' => 'node', 'subjectConnectionId' => self::CONNECTION,
            'subjectClusterId' => self::CLUSTER, 'nodeId' => self::NODE, 'guestId' => null, 'selectionValue' => 'include'];
        foreach ([
            [ConfigurationCommandType::SelectionUpsert, 4, 'include'],
            [ConfigurationCommandType::SelectionUpsert, 5, 'exclude'],
            [ConfigurationCommandType::SelectionDisable, 6, 'exclude'],
            [ConfigurationCommandType::SelectionUpsert, 7, 'include'],
        ] as [$type, $revision, $value]) {
            $entry['selectionValue'] = $value;
            self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
                $type, self::POLICY, $revision, 'lifecycle-selection-'.$revision, random_bytes(16), ['entries' => [$entry]],
            ), $principal)->status);
        }
        self::assertSame('active', $this->connection()->fetchOne('SELECT status FROM backup_policy_assignments WHERE id = ?', [self::ASSIGNMENT]));
        self::assertSame('include', $this->connection()->fetchOne('SELECT selection_value FROM backup_policy_assignments WHERE id = ?', [self::ASSIGNMENT]));

        $override = ['id' => self::OVERRIDE, 'guestId' => self::GUEST, 'backupMode' => 'snapshot', 'compression' => 'zstd',
            'legacyMaxfiles' => null, 'keepAll' => null, 'keepLast' => 2, 'keepHourly' => null, 'keepDaily' => null,
            'keepWeekly' => null, 'keepMonthly' => null, 'keepYearly' => null];
        foreach ([
            [ConfigurationCommandType::GuestOverrideUpsert, 8, 2],
            [ConfigurationCommandType::GuestOverrideUpsert, 9, 4],
            [ConfigurationCommandType::GuestOverrideDisable, 10, 4],
            [ConfigurationCommandType::GuestOverrideUpsert, 11, 6],
        ] as [$type, $revision, $keepLast]) {
            $override['keepLast'] = $keepLast;
            self::assertSame(ConfigurationCommandStatus::Applied, $repository->execute(new ConfigurationCommand(
                $type, self::POLICY, $revision, 'lifecycle-override-'.$revision, random_bytes(16), ['entries' => [$override]],
            ), $principal)->status);
        }
        self::assertSame('active', $this->connection()->fetchOne('SELECT status FROM backup_policy_guest_overrides WHERE id = ?', [self::OVERRIDE]));
        $persistedKeepLast = $this->connection()->fetchOne('SELECT keep_last FROM backup_policy_guest_overrides WHERE id = ?', [self::OVERRIDE]);
        self::assertTrue(6 === $persistedKeepLast || '6' === $persistedKeepLast);
    }

    /** @return array<string, mixed> */
    private function targetPayload(string $displayName): array
    {
        return ['connectionId' => self::CONNECTION, 'clusterId' => self::CLUSTER, 'storageId' => self::STORAGE,
            'displayName' => $displayName, 'minimumFreeBytes' => '2048', 'fixedParallelLimit' => 2,
            'pbsConnectionId' => null, 'pbsDatastoreId' => null, 'pbsNamespaceId' => null, 'allowedNodeIds' => [self::NODE]];
    }

    /** @return array<string, mixed> */
    private function policyPayload(string $displayName): array
    {
        return ['connectionId' => self::CONNECTION, 'clusterId' => self::CLUSTER, 'targetId' => self::TARGET,
            'displayName' => $displayName, 'priority' => 200, 'backupMode' => 'snapshot', 'compression' => 'zstd',
            'maximumAgeSeconds' => '86400', 'bytesWrittenThreshold' => '1024', 'cooldownSeconds' => '120',
            'schedule' => 'collector_cycle', 'legacyMaxfiles' => null, 'keepAll' => false, 'keepLast' => 3,
            'keepHourly' => null, 'keepDaily' => null, 'keepWeekly' => null, 'keepMonthly' => null, 'keepYearly' => null,
            'retentionExecutionEnabled' => false, 'failureNotificationRecipients' => ['ops@example.test']];
    }

    private function seedContext(): void
    {
        $now = '2026-07-12 12:00:00.000000';
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->connection()->insert('users', ['id' => self::USER, 'username' => 'admin', 'display_name' => 'Admin',
                'password_hash' => '$argon2id$dummy', 'enabled' => 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->connection()->insert('proxmox_connections', ['id' => self::CONNECTION, 'display_name' => 'PVE', 'product' => 'pve',
                'enabled' => 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->connection()->insert('pve_clusters', ['id' => self::CLUSTER, 'connection_id' => self::CONNECTION,
                'external_name' => 'cluster', 'topology' => 'clustered', 'inventory_state' => 'active',
                'first_seen_run_id' => str_repeat('x', 16), 'last_seen_run_id' => str_repeat('x', 16), 'first_seen_at' => $now, 'last_seen_at' => $now]);
            $this->connection()->insert('pve_nodes', ['id' => self::NODE, 'connection_id' => self::CONNECTION, 'cluster_id' => self::CLUSTER,
                'node_name' => 'node', 'api_status' => 'online', 'inventory_state' => 'active',
                'first_seen_run_id' => str_repeat('x', 16), 'last_seen_run_id' => str_repeat('x', 16), 'first_seen_at' => $now, 'last_seen_at' => $now]);
            $this->connection()->insert('pve_storages', ['id' => self::STORAGE, 'connection_id' => self::CONNECTION, 'cluster_id' => self::CLUSTER,
                'storage_name' => 'store', 'storage_type' => 'dir', 'supports_backup' => 1, 'shared' => 1, 'inventory_state' => 'active',
                'disabled' => 0, 'content_json' => '["backup"]',
                'first_seen_run_id' => str_repeat('x', 16), 'last_seen_run_id' => str_repeat('x', 16),
                'first_seen_at' => $now, 'last_seen_at' => $now]);
            $this->connection()->insert('guests', ['id' => self::GUEST, 'connection_id' => self::CONNECTION, 'cluster_id' => self::CLUSTER,
                'guest_type' => 'qemu', 'vmid' => 100, 'name' => 'guest', 'is_template' => 0,
                'provisioned_size_bytes' => '1024', 'inventory_state' => 'active',
                'first_seen_run_id' => str_repeat('x', 16), 'last_seen_run_id' => str_repeat('x', 16),
                'first_seen_at' => $now, 'last_seen_at' => $now]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function seedPveCapability(int $major): void
    {
        $payload = json_encode(['major' => $major], JSON_THROW_ON_ERROR);
        $this->connection()->insert('proxmox_capability_snapshots', [
            'id' => str_repeat('v', 16),
            'connection_id' => self::CONNECTION,
            'product' => 'pve',
            'version_major' => $major,
            'version_minor' => 0,
            'raw_version' => $major.'.0',
            'profile_version' => 1,
            'capabilities_json' => $payload,
            'snapshot_hash' => hash('sha256', $payload, true),
            'first_observed_at' => '2026-07-12 12:00:00.000000',
            'last_observed_at' => '2026-07-12 12:00:00.000000',
        ]);
    }
}
