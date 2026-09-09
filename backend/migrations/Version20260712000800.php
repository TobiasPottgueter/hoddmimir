<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fail-closed local RBAC, session, login-throttle, and append-only audit persistence.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE users (
    id BINARY(16) NOT NULL,
    username VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    disabled_at DATETIME(6) NULL,
    last_login_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_users_username UNIQUE (username),
    CONSTRAINT chk_users_username CHECK (username REGEXP '^[a-z0-9][a-z0-9._-]{2,63}$'),
    CONSTRAINT chk_users_display_name CHECK (CHAR_LENGTH(TRIM(display_name)) BETWEEN 1 AND 190),
    CONSTRAINT chk_users_password_hash CHECK (password_hash LIKE '$argon2id$%'),
    CONSTRAINT chk_users_enabled CHECK (enabled IN (0, 1)),
    CONSTRAINT chk_users_revision CHECK (revision > 0),
    CONSTRAINT chk_users_time CHECK (
        created_at <= updated_at
        AND (last_login_at IS NULL OR last_login_at >= created_at)
        AND ((enabled = 1 AND disabled_at IS NULL)
            OR (enabled = 0 AND disabled_at IS NOT NULL AND disabled_at BETWEEN created_at AND updated_at))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE roles (
    id BINARY(16) NOT NULL,
    role_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_roles_name UNIQUE (role_name),
    CONSTRAINT chk_roles_name CHECK (role_name IN ('admin', 'viewer')),
    CONSTRAINT chk_roles_display_name CHECK (CHAR_LENGTH(TRIM(display_name)) BETWEEN 1 AND 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE permissions (
    id BINARY(16) NOT NULL,
    permission_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_permissions_name UNIQUE (permission_name),
    CONSTRAINT chk_permissions_name CHECK (permission_name IN (
        'inventory.read', 'backup_configuration.manage', 'backup_operations.manage', 'audit.read'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE user_roles (
    user_id BINARY(16) NOT NULL,
    role_id BINARY(16) NOT NULL,
    assigned_at DATETIME(6) NOT NULL,
    assigned_by_user_id BINARY(16) NULL,
    PRIMARY KEY (user_id, role_id),
    INDEX idx_user_roles_role (role_id, user_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_roles_assigner FOREIGN KEY (assigned_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE role_permissions (
    role_id BINARY(16) NOT NULL,
    permission_id BINARY(16) NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    INDEX idx_role_permissions_permission (permission_id, role_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE web_sessions (
    id BINARY(16) NOT NULL,
    user_id BINARY(16) NOT NULL,
    token_hash BINARY(32) NOT NULL,
    csrf_secret_hash BINARY(32) NOT NULL,
    issued_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    idle_expires_at DATETIME(6) NOT NULL,
    absolute_expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_web_sessions_token_hash UNIQUE (token_hash),
    INDEX idx_web_sessions_user_expiry (user_id, revoked_at, idle_expires_at, absolute_expires_at),
    INDEX idx_web_sessions_expiry (revoked_at, idle_expires_at, absolute_expires_at),
    CONSTRAINT fk_web_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_web_sessions_time CHECK (
        issued_at <= last_seen_at
        AND absolute_expires_at = issued_at + INTERVAL 12 HOUR
        AND idle_expires_at > last_seen_at
        AND idle_expires_at <= last_seen_at + INTERVAL 30 MINUTE
        AND idle_expires_at <= absolute_expires_at
        AND (revoked_at IS NULL OR revoked_at >= issued_at)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE login_attempts (
    username VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_address VARBINARY(16) NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    failure_count TINYINT UNSIGNED NOT NULL,
    last_failed_at DATETIME(6) NOT NULL,
    locked_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (username, ip_address),
    INDEX idx_login_attempts_locked (locked_until, updated_at),
    CONSTRAINT chk_login_attempts_username CHECK (username REGEXP '^[a-z0-9][a-z0-9._-]{2,63}$'),
    CONSTRAINT chk_login_attempts_ip CHECK (OCTET_LENGTH(ip_address) IN (4, 16)),
    CONSTRAINT chk_login_attempts_count CHECK (failure_count BETWEEN 1 AND 5),
    CONSTRAINT chk_login_attempts_window CHECK (
        window_started_at <= last_failed_at
        AND last_failed_at < window_started_at + INTERVAL 15 MINUTE
        AND updated_at >= last_failed_at
    ),
    CONSTRAINT chk_login_attempts_lock CHECK (
        (failure_count < 5 AND locked_until IS NULL)
        OR (failure_count = 5 AND locked_until = last_failed_at + INTERVAL 15 MINUTE)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE audit_events (
    id BINARY(16) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    actor_user_id BINARY(16) NULL,
    actor_session_id BINARY(16) NULL,
    event_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    subject_id BINARY(16) NULL,
    reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    correlation_id BINARY(16) NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_audit_events_occurred (occurred_at, id),
    INDEX idx_audit_events_actor (actor_user_id, occurred_at, id),
    INDEX idx_audit_events_subject (subject_type, subject_id, occurred_at, id),
    CONSTRAINT fk_audit_events_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_audit_events_session FOREIGN KEY (actor_session_id) REFERENCES web_sessions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_audit_events_type CHECK (event_type IN (
        'first_admin_created', 'user_created', 'user_disabled', 'role_assigned', 'role_removed',
        'login_succeeded', 'login_failed', 'session_created', 'session_revoked'
    )),
    CONSTRAINT chk_audit_events_outcome CHECK (outcome IN ('succeeded', 'denied')),
    CONSTRAINT chk_audit_events_subject CHECK ((subject_type IS NULL) = (subject_id IS NULL)),
    CONSTRAINT chk_audit_events_subject_type CHECK (
        subject_type IS NULL OR subject_type IN ('user', 'session', 'role')
    ),
    CONSTRAINT chk_audit_events_reason CHECK (
        reason_code IS NULL OR reason_code REGEXP '^[a-z0-9][a-z0-9._-]{0,63}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->seedClosedRbac();
        $this->runtimePrivileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->runtimePrivileges('REVOKE');
        $this->addSql('DROP TABLE audit_events');
        $this->addSql('DROP TABLE login_attempts');
        $this->addSql('DROP TABLE web_sessions');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('DROP TABLE permissions');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE users');
    }

    private function seedClosedRbac(): void
    {
        $this->addSql("INSERT INTO roles (id, role_name, display_name) VALUES
            (UNHEX('00000000000000000000000000000001'), 'admin', 'Administrator'),
            (UNHEX('00000000000000000000000000000002'), 'viewer', 'Viewer')");
        $this->addSql("INSERT INTO permissions (id, permission_name) VALUES
            (UNHEX('00000000000000000000000000000101'), 'inventory.read'),
            (UNHEX('00000000000000000000000000000102'), 'backup_configuration.manage'),
            (UNHEX('00000000000000000000000000000103'), 'backup_operations.manage'),
            (UNHEX('00000000000000000000000000000104'), 'audit.read')");
        $this->addSql("INSERT INTO role_permissions (role_id, permission_id)
            SELECT UNHEX('00000000000000000000000000000001'), id FROM permissions");
        $this->addSql("INSERT INTO role_permissions (role_id, permission_id) VALUES
            (UNHEX('00000000000000000000000000000002'), UNHEX('00000000000000000000000000000101'))");
    }

    private function runtimePrivileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database)
            || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A security grant identifier is invalid.');
        }

        $grants = [
            'users' => 'SELECT',
            'roles' => 'SELECT',
            'permissions' => 'SELECT',
            'user_roles' => 'SELECT',
            'role_permissions' => 'SELECT',
            'web_sessions' => 'SELECT, INSERT, UPDATE (last_seen_at, idle_expires_at, revoked_at)',
            'login_attempts' => 'SELECT, INSERT, UPDATE, DELETE',
            'audit_events' => 'SELECT, INSERT',
        ];
        foreach ($grants as $table => $privileges) {
            $this->addSql(sprintf(
                "%s %s ON `%s`.`%s` %s 'hoddmimir_web'@'%%'",
                $operation,
                $privileges,
                $database,
                $table,
                'GRANT' === $operation ? 'TO' : 'FROM',
            ));
        }
    }
}
