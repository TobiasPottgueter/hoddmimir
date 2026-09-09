<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add revisioned idempotent configuration commands and activatable backup targets.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE backup_targets DROP CONSTRAINT chk_backup_targets_status');
        $this->addSql('ALTER TABLE backup_targets DROP CONSTRAINT chk_backup_targets_time');
        $this->addSql('ALTER TABLE pbs_datastores ADD CONSTRAINT uq_pbs_datastores_connection_id UNIQUE (connection_id, id)');
        $this->addSql(<<<'SQL'
ALTER TABLE backup_targets
    ADD fixed_parallel_limit SMALLINT UNSIGNED NULL AFTER minimum_free_bytes,
    ADD pbs_connection_id BINARY(16) NULL AFTER fixed_parallel_limit,
    ADD pbs_datastore_id BINARY(16) NULL AFTER pbs_connection_id,
    ADD pbs_namespace_id BINARY(16) NULL AFTER pbs_datastore_id,
    MODIFY disabled_at DATETIME(6) NULL,
    ADD CONSTRAINT fk_backup_targets_pbs_connection FOREIGN KEY (pbs_connection_id)
        REFERENCES proxmox_connections (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_backup_targets_pbs_datastore FOREIGN KEY (pbs_connection_id, pbs_datastore_id)
        REFERENCES pbs_datastores (connection_id, id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_backup_targets_pbs_namespace FOREIGN KEY (pbs_datastore_id, pbs_namespace_id)
        REFERENCES pbs_namespaces (datastore_id, id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_backup_targets_status CHECK (status IN ('enabled', 'disabled')),
    ADD CONSTRAINT chk_backup_targets_parallel CHECK (fixed_parallel_limit IS NULL OR fixed_parallel_limit BETWEEN 1 AND 100),
    ADD CONSTRAINT chk_backup_targets_pbs_binding CHECK (
        (pbs_connection_id IS NULL AND pbs_datastore_id IS NULL AND pbs_namespace_id IS NULL)
        OR (pbs_connection_id IS NOT NULL AND pbs_datastore_id IS NOT NULL)
    ),
    ADD CONSTRAINT chk_backup_targets_activation CHECK (
        status <> 'enabled' OR (minimum_free_bytes IS NOT NULL AND fixed_parallel_limit IS NOT NULL)
    ),
    ADD CONSTRAINT chk_backup_targets_time CHECK (
        created_at <= updated_at
        AND ((status = 'disabled' AND disabled_at BETWEEN created_at AND updated_at)
            OR (status = 'enabled' AND disabled_at IS NULL))
    )
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE configuration_command_idempotency (
    actor_user_id BINARY(16) NOT NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_id BINARY(16) NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    result_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_revision BIGINT UNSIGNED NULL,
    blocker_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (actor_user_id, idempotency_key),
    CONSTRAINT fk_configuration_idempotency_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_configuration_idempotency_key CHECK (
        idempotency_key REGEXP '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
    ),
    CONSTRAINT chk_configuration_idempotency_command CHECK (command_type IN (
        'target.create', 'target.update', 'target.enable', 'target.disable',
        'policy.create', 'policy.update', 'policy.enable', 'policy.disable',
        'selection.upsert', 'selection.disable', 'guest_override.upsert', 'guest_override.disable'
    )),
    CONSTRAINT chk_configuration_idempotency_result CHECK (
        result_status IN ('applied', 'conflict', 'blocked', 'denied')
        AND ((result_status = 'applied' AND result_revision IS NOT NULL AND blocker_code IS NULL)
            OR (result_status = 'conflict' AND result_revision IS NOT NULL AND blocker_code IS NULL)
            OR (result_status IN ('blocked', 'denied') AND blocker_code IS NOT NULL))
    ),
    CONSTRAINT chk_configuration_idempotency_blocker CHECK (
        blocker_code IS NULL OR blocker_code REGEXP '^[a-z0-9][a-z0-9._-]{0,63}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->replaceAuditChecks(true);
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        $this->replaceAuditChecks(false);
        $this->addSql('DROP TABLE configuration_command_idempotency');
        $this->addSql('ALTER TABLE backup_targets DROP FOREIGN KEY fk_backup_targets_pbs_namespace');
        $this->addSql('ALTER TABLE backup_targets DROP FOREIGN KEY fk_backup_targets_pbs_datastore');
        $this->addSql('ALTER TABLE backup_targets DROP FOREIGN KEY fk_backup_targets_pbs_connection');
        $this->addSql('ALTER TABLE backup_targets DROP CONSTRAINT chk_backup_targets_status');
        $this->addSql('ALTER TABLE backup_targets DROP CONSTRAINT chk_backup_targets_parallel');
        $this->addSql('ALTER TABLE backup_targets DROP CONSTRAINT chk_backup_targets_pbs_binding');
        $this->addSql('ALTER TABLE backup_targets DROP CONSTRAINT chk_backup_targets_activation');
        $this->addSql('ALTER TABLE backup_targets DROP CONSTRAINT chk_backup_targets_time');
        $this->addSql(<<<'SQL'
ALTER TABLE backup_targets
    DROP COLUMN pbs_namespace_id,
    DROP COLUMN pbs_datastore_id,
    DROP COLUMN pbs_connection_id,
    DROP COLUMN fixed_parallel_limit,
    MODIFY disabled_at DATETIME(6) NOT NULL,
    ADD CONSTRAINT chk_backup_targets_status CHECK (status = 'disabled'),
    ADD CONSTRAINT chk_backup_targets_time CHECK (created_at <= disabled_at AND disabled_at <= updated_at)
SQL);
        $this->addSql('ALTER TABLE pbs_datastores DROP INDEX uq_pbs_datastores_connection_id');
    }

    private function replaceAuditChecks(bool $expanded): void
    {
        $this->addSql('ALTER TABLE audit_events DROP CONSTRAINT chk_audit_events_type');
        $this->addSql('ALTER TABLE audit_events DROP CONSTRAINT chk_audit_events_subject_type');
        $types = $expanded
            ? "'first_admin_created', 'user_created', 'user_disabled', 'role_assigned', 'role_removed', 'login_succeeded', 'login_failed', 'session_created', 'session_revoked', 'target_created', 'target_updated', 'target_enabled', 'target_disabled', 'policy_created', 'policy_updated', 'policy_enabled', 'policy_disabled', 'selection_upserted', 'selection_disabled', 'guest_override_upserted', 'guest_override_disabled'"
            : "'first_admin_created', 'user_created', 'user_disabled', 'role_assigned', 'role_removed', 'login_succeeded', 'login_failed', 'session_created', 'session_revoked'";
        $subjects = $expanded ? "'user', 'session', 'role', 'target', 'policy', 'selection', 'guest_override'" : "'user', 'session', 'role'";
        $this->addSql(sprintf('ALTER TABLE audit_events ADD CONSTRAINT chk_audit_events_type CHECK (event_type IN (%s))', $types));
        $this->addSql(sprintf('ALTER TABLE audit_events ADD CONSTRAINT chk_audit_events_subject_type CHECK (subject_type IS NULL OR subject_type IN (%s))', $subjects));
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A configuration-command grant identifier is invalid.');
        }
        $grants = [
            'backup_targets' => 'INSERT, UPDATE (display_name, status, revision, minimum_free_bytes, fixed_parallel_limit, pbs_connection_id, pbs_datastore_id, pbs_namespace_id, updated_at, disabled_at)',
            'backup_target_allowed_nodes' => 'INSERT, DELETE',
            'backup_policies' => 'INSERT, UPDATE (target_id, display_name, status, revision, policy_priority, backup_mode, compression, maximum_age_seconds, bytes_written_threshold, cooldown_seconds, schedule, legacy_maxfiles, keep_all, keep_last, keep_hourly, keep_daily, keep_weekly, keep_monthly, keep_yearly, retention_execution_enabled, updated_at, disabled_at)',
            'backup_policy_assignments' => 'INSERT, UPDATE (selection_value, status, revision, updated_at, disabled_at)',
            'backup_policy_guest_overrides' => 'INSERT, UPDATE (backup_mode, compression, legacy_maxfiles, keep_all, keep_last, keep_hourly, keep_daily, keep_weekly, keep_monthly, keep_yearly, status, revision, updated_at, disabled_at)',
            'configuration_command_idempotency' => 'SELECT, INSERT',
        ];
        foreach ($grants as $table => $privilege) {
            $this->addSql(sprintf("%s %s ON `%s`.`%s` %s 'hoddmimir_web'@'%%'", $operation, $privilege, $database, $table, 'GRANT' === $operation ? 'TO' : 'FROM'));
        }
        foreach (['backup_targets', 'backup_target_allowed_nodes', 'backup_policies', 'backup_policy_assignments', 'backup_policy_guest_overrides'] as $table) {
            $this->addSql(sprintf("%s SELECT ON `%s`.`%s` %s 'hoddmimir_backup_worker'@'%%'", $operation, $database, $table, 'GRANT' === $operation ? 'TO' : 'FROM'));
        }
    }
}
