<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712001400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add revisioned security administration, security.manage, and least-privilege WebApp grants.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE permissions DROP CONSTRAINT chk_permissions_name');
        $this->addSql("ALTER TABLE permissions ADD CONSTRAINT chk_permissions_name CHECK (permission_name IN ('inventory.read', 'backup_configuration.manage', 'backup_operations.manage', 'audit.read', 'security.manage'))");
        $this->addSql("INSERT INTO permissions (id, permission_name) VALUES (UNHEX('00000000000000000000000000000105'), 'security.manage')");
        $this->addSql("INSERT INTO role_permissions (role_id, permission_id) VALUES (UNHEX('00000000000000000000000000000001'), UNHEX('00000000000000000000000000000105'))");

        $this->addSql(<<<'SQL'
CREATE TABLE security_command_idempotency (
    actor_user_id BINARY(16) NOT NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_user_id BINARY(16) NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    password_replay_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    result_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_revision BIGINT UNSIGNED NULL,
    blocker_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (actor_user_id, idempotency_key),
    CONSTRAINT fk_security_idempotency_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_security_idempotency_key CHECK (idempotency_key REGEXP '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
    CONSTRAINT chk_security_idempotency_command CHECK (command_type IN ('user.create', 'user.update', 'user.disable', 'user.roles.replace')),
    CONSTRAINT chk_security_idempotency_password CHECK (password_replay_hash IS NULL OR password_replay_hash LIKE '$argon2id$%'),
    CONSTRAINT chk_security_idempotency_result CHECK (
        result_status IN ('applied', 'conflict', 'blocked', 'denied')
        AND ((result_status IN ('applied', 'conflict') AND result_revision IS NOT NULL AND blocker_code IS NULL)
            OR (result_status IN ('blocked', 'denied') AND result_revision IS NULL AND blocker_code IS NOT NULL))
    ),
    CONSTRAINT chk_security_idempotency_blocker CHECK (blocker_code IS NULL OR blocker_code REGEXP '^[a-z0-9][a-z0-9._-]{0,63}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->replaceAuditType(true);
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        $this->replaceAuditType(false);
        $this->addSql('DROP TABLE security_command_idempotency');
        $this->addSql("DELETE FROM role_permissions WHERE permission_id = UNHEX('00000000000000000000000000000105')");
        $this->addSql("DELETE FROM permissions WHERE id = UNHEX('00000000000000000000000000000105')");
        $this->addSql('ALTER TABLE permissions DROP CONSTRAINT chk_permissions_name');
        $this->addSql("ALTER TABLE permissions ADD CONSTRAINT chk_permissions_name CHECK (permission_name IN ('inventory.read', 'backup_configuration.manage', 'backup_operations.manage', 'audit.read'))");
    }

    private function replaceAuditType(bool $expanded): void
    {
        $this->addSql('ALTER TABLE audit_events DROP CONSTRAINT chk_audit_events_type');
        $types = "'first_admin_created', 'user_created', 'user_disabled', 'role_assigned', 'role_removed', 'login_succeeded', 'login_failed', 'session_created', 'session_revoked', 'target_created', 'target_updated', 'target_enabled', 'target_disabled', 'policy_created', 'policy_updated', 'policy_enabled', 'policy_disabled', 'selection_upserted', 'selection_disabled', 'guest_override_upserted', 'guest_override_disabled'";
        if ($expanded) {
            $types .= ", 'user_updated'";
        }
        $this->addSql(sprintf('ALTER TABLE audit_events ADD CONSTRAINT chk_audit_events_type CHECK (event_type IN (%s))', $types));
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A security-administration grant identifier is invalid.');
        }
        $grants = [
            'users' => 'UPDATE (display_name, password_hash, enabled, revision, updated_at, disabled_at)',
            'user_roles' => 'DELETE',
            'security_command_idempotency' => 'SELECT, INSERT',
        ];
        foreach ($grants as $table => $privilege) {
            $this->addSql(sprintf("%s %s ON `%s`.`%s` %s 'hoddmimir_web'@'%%'", $operation, $privilege, $database, $table, 'GRANT' === $operation ? 'TO' : 'FROM'));
        }
    }
}
