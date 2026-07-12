<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fenced PBS namespace and snapshot content inventory.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE pbs_content_runs (
    id BINARY(16) NOT NULL,
    parent_run_id BINARY(16) NOT NULL,
    cycle_token BINARY(16) NOT NULL,
    collector_fencing_token BIGINT UNSIGNED NOT NULL,
    connection_id BINARY(16) NOT NULL,
    endpoint_id BINARY(16) NOT NULL,
    expected_connection_revision BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    namespaces_seen INT UNSIGNED NOT NULL DEFAULT 0,
    snapshots_seen INT UNSIGNED NOT NULL DEFAULT 0,
    objects_created INT UNSIGNED NOT NULL DEFAULT 0,
    objects_updated INT UNSIGNED NOT NULL DEFAULT 0,
    objects_archived INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME(6) NOT NULL,
    heartbeat_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    applied_at DATETIME(6) NULL,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pbs_content_runs_parent UNIQUE (parent_run_id),
    CONSTRAINT uq_pbs_content_runs_connection_id UNIQUE (connection_id, id),
    CONSTRAINT fk_pbs_content_runs_parent FOREIGN KEY (connection_id, parent_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pbs_content_runs_endpoint FOREIGN KEY (connection_id, endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_content_runs_status CHECK (status IN ('running', 'succeeded', 'partial', 'failed')),
    CONSTRAINT chk_pbs_content_runs_terminal CHECK (
        (status = 'running' AND finished_at IS NULL AND applied_at IS NULL AND error_code IS NULL)
        OR (status IN ('succeeded', 'partial') AND finished_at IS NOT NULL AND applied_at IS NOT NULL AND error_code IS NULL)
        OR (status = 'failed' AND finished_at IS NOT NULL AND applied_at IS NULL AND error_code IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_content_scope_results (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    content_run_id BINARY(16) NOT NULL,
    datastore_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    namespace_path VARCHAR(256) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rows_read INT UNSIGNED NOT NULL,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pbs_content_scope UNIQUE (content_run_id, datastore_name, scope_type, namespace_path),
    CONSTRAINT fk_pbs_content_scope_run FOREIGN KEY (connection_id, content_run_id)
        REFERENCES pbs_content_runs (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_pbs_content_scope_type CHECK (scope_type IN ('pbs_namespaces', 'pbs_snapshots')),
    CONSTRAINT chk_pbs_content_scope_namespace CHECK (
        (scope_type = 'pbs_namespaces' AND namespace_path = '@namespaces')
        OR (scope_type = 'pbs_snapshots' AND namespace_path <> '@namespaces')
    ),
    CONSTRAINT chk_pbs_content_scope_status CHECK (status IN ('complete', 'partial', 'failed')),
    CONSTRAINT chk_pbs_content_scope_error CHECK (
        (status = 'complete' AND error_code IS NULL)
        OR (status IN ('partial', 'failed') AND error_code IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_namespaces (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    server_id BINARY(16) NOT NULL,
    datastore_id BINARY(16) NOT NULL,
    namespace_path VARCHAR(256) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    namespace_depth TINYINT UNSIGNED NOT NULL,
    parent_namespace_id BINARY(16) NULL,
    inventory_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pbs_namespaces_path UNIQUE (datastore_id, namespace_path),
    CONSTRAINT uq_pbs_namespaces_connection_id UNIQUE (connection_id, id),
    CONSTRAINT uq_pbs_namespaces_datastore_id UNIQUE (datastore_id, id),
    CONSTRAINT fk_pbs_namespaces_datastore FOREIGN KEY (connection_id, server_id, datastore_id)
        REFERENCES pbs_datastores (connection_id, server_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pbs_namespaces_parent FOREIGN KEY (datastore_id, parent_namespace_id)
        REFERENCES pbs_namespaces (datastore_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_namespaces_first_run FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES pbs_content_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_namespaces_last_run FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES pbs_content_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_namespaces_depth CHECK (namespace_depth <= 8),
    CONSTRAINT chk_pbs_namespaces_path_depth CHECK (
        (namespace_path = '' AND namespace_depth = 0)
        OR (namespace_path <> '' AND namespace_depth BETWEEN 1 AND 8)
    ),
    CONSTRAINT chk_pbs_namespaces_state CHECK (inventory_state IN ('active', 'archived')),
    CONSTRAINT chk_pbs_namespaces_archive CHECK (
        (inventory_state = 'active' AND archived_at IS NULL)
        OR (inventory_state = 'archived' AND archived_at IS NOT NULL)
    ),
    CONSTRAINT chk_pbs_namespaces_seen CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_backup_groups (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    namespace_id BINARY(16) NOT NULL,
    backup_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    backup_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_auth_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    inventory_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pbs_backup_groups_identity UNIQUE (namespace_id, backup_type, backup_id),
    CONSTRAINT uq_pbs_backup_groups_connection_id UNIQUE (connection_id, id),
    CONSTRAINT fk_pbs_backup_groups_namespace FOREIGN KEY (connection_id, namespace_id)
        REFERENCES pbs_namespaces (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pbs_backup_groups_first_run FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES pbs_content_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_backup_groups_last_run FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES pbs_content_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_backup_groups_type CHECK (backup_type IN ('vm', 'ct', 'host')),
    CONSTRAINT chk_pbs_backup_groups_state CHECK (inventory_state IN ('active', 'archived')),
    CONSTRAINT chk_pbs_backup_groups_archive CHECK (
        (inventory_state = 'active' AND archived_at IS NULL)
        OR (inventory_state = 'archived' AND archived_at IS NOT NULL)
    ),
    CONSTRAINT chk_pbs_backup_groups_seen CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_snapshots (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    group_id BINARY(16) NOT NULL,
    backup_time DATETIME(6) NOT NULL,
    protected TINYINT(1) NOT NULL,
    size_bytes BIGINT UNSIGNED NULL,
    comment VARCHAR(128) NULL,
    encryption_fingerprint VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verification_state VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verification_upid VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NULL,
    files_json LONGTEXT NOT NULL,
    inventory_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pbs_snapshots_identity UNIQUE (group_id, backup_time),
    CONSTRAINT fk_pbs_snapshots_group FOREIGN KEY (connection_id, group_id)
        REFERENCES pbs_backup_groups (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pbs_snapshots_first_run FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES pbs_content_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_snapshots_last_run FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES pbs_content_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_snapshots_protected CHECK (protected IN (0, 1)),
    CONSTRAINT chk_pbs_snapshots_verification CHECK (
        (verification_state IS NULL AND verification_upid IS NULL)
        OR (verification_state IN ('ok', 'failed') AND verification_upid IS NOT NULL)
    ),
    CONSTRAINT chk_pbs_snapshots_files CHECK (JSON_VALID(files_json) AND JSON_TYPE(files_json) = 'ARRAY'),
    CONSTRAINT chk_pbs_snapshots_state CHECK (inventory_state IN ('active', 'archived')),
    CONSTRAINT chk_pbs_snapshots_archive CHECK (
        (inventory_state = 'active' AND archived_at IS NULL)
        OR (inventory_state = 'archived' AND archived_at IS NOT NULL)
    ),
    CONSTRAINT chk_pbs_snapshots_seen CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_content_runs');
        $this->grant('hoddmimir_collector', 'SELECT, INSERT', 'pbs_content_scope_results');
        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_namespaces');
        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_backup_groups');
        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_snapshots');
    }

    public function down(Schema $schema): void
    {
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_snapshots');
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_backup_groups');
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_namespaces');
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT', 'pbs_content_scope_results');
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_content_runs');
        $this->addSql('DROP TABLE pbs_snapshots');
        $this->addSql('DROP TABLE pbs_backup_groups');
        $this->addSql('DROP TABLE pbs_namespaces');
        $this->addSql('DROP TABLE pbs_content_scope_results');
        $this->addSql('DROP TABLE pbs_content_runs');
    }

    private function grant(string $user, string $privileges, string $table): void
    {
        $this->privilegeStatement('GRANT', $user, $privileges, $table);
    }

    private function revoke(string $user, string $privileges, string $table): void
    {
        $this->privilegeStatement('REVOKE', $user, $privileges, $table);
    }

    private function privilegeStatement(string $operation, string $user, string $privileges, string $table): void
    {
        $database = $this->connection->getDatabase();
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || 1 !== preg_match('/^[a-z_]+$/D', $user)
            || 1 !== preg_match('/^[a-z_]+$/D', $table)) {
            throw new \RuntimeException('A PBS content grant identifier is invalid.');
        }
        $this->addSql(sprintf(
            "%s %s ON `%s`.`%s` %s '%s'@'%%'",
            $operation,
            $privileges,
            $database,
            $table,
            'GRANT' === $operation ? 'TO' : 'FROM',
            $user,
        ));
    }
}
