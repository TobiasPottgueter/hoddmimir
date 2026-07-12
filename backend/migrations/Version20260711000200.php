<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add runtime PBS server and installation-wide datastore inventory persistence.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE proxmox_installation_bindings
    DROP CONSTRAINT chk_proxmox_installation_bindings_identity,
    ADD COLUMN legacy_endpoint_id BINARY(16) NULL AFTER identity_value,
    ADD CONSTRAINT fk_proxmox_installation_bindings_legacy_endpoint
        FOREIGN KEY (connection_id, legacy_endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_proxmox_installation_bindings_identity CHECK (
        OCTET_LENGTH(identity_value) BETWEEN 1 AND 255
        AND (
            (product = 'pve' AND identity_kind IN ('pve_cluster', 'pve_standalone')
                AND legacy_endpoint_id IS NULL)
            OR (product = 'pbs' AND identity_kind = 'pbs_instance'
                AND legacy_endpoint_id IS NULL
                AND OCTET_LENGTH(identity_value) = 32
                AND identity_value REGEXP '^[0-9a-f]{32}$')
            OR (product = 'pbs' AND identity_kind = 'pbs_legacy_node'
                AND legacy_endpoint_id IS NOT NULL)
        )
    )
SQL);

        $this->addSql(<<<'SQL'
ALTER TABLE inventory_sync_scope_results
    DROP CONSTRAINT chk_inventory_sync_scope_type,
    ADD CONSTRAINT chk_inventory_sync_scope_type CHECK (
        scope_type IN (
            'pve_topology', 'pve_guests', 'pve_storages', 'pve_node_storages',
            'pbs_system', 'pbs_datastores', 'pbs_datastore_status'
        )
    )
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_servers (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    node_name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version_major SMALLINT UNSIGNED NOT NULL,
    version_minor SMALLINT UNSIGNED NOT NULL,
    version_patch SMALLINT UNSIGNED NULL,
    version_text VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    release_text VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    repo_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pbs_servers_connection UNIQUE (connection_id),
    CONSTRAINT uq_pbs_servers_connection_id UNIQUE (connection_id, id),
    CONSTRAINT fk_pbs_servers_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_servers_first_run FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_servers_last_run FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_servers_version CHECK (
        version_major IN (3, 4) AND OCTET_LENGTH(version_text) BETWEEN 1 AND 64
        AND OCTET_LENGTH(release_text) BETWEEN 1 AND 64
        AND OCTET_LENGTH(repo_id) BETWEEN 1 AND 190
    ),
    CONSTRAINT chk_pbs_servers_seen_time CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_server_status (
    server_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    uptime_seconds BIGINT UNSIGNED NOT NULL,
    memory_total_bytes BIGINT UNSIGNED NOT NULL,
    memory_used_bytes BIGINT UNSIGNED NOT NULL,
    root_total_bytes BIGINT UNSIGNED NOT NULL,
    root_used_bytes BIGINT UNSIGNED NOT NULL,
    root_available_bytes BIGINT UNSIGNED NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    sync_run_id BINARY(16) NOT NULL,
    PRIMARY KEY (server_id),
    CONSTRAINT fk_pbs_server_status_server FOREIGN KEY (connection_id, server_id)
        REFERENCES pbs_servers (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pbs_server_status_run FOREIGN KEY (connection_id, sync_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_server_status_memory CHECK (memory_used_bytes <= memory_total_bytes),
    CONSTRAINT chk_pbs_server_status_root CHECK (
        root_used_bytes <= root_total_bytes AND root_available_bytes <= root_total_bytes
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_datastores (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    server_id BINARY(16) NOT NULL,
    datastore_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    backend_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mount_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    maintenance_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    allows_backup_writes TINYINT(1) NOT NULL,
    inventory_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pbs_datastores_name UNIQUE (server_id, datastore_name),
    CONSTRAINT uq_pbs_datastores_connection_server_id UNIQUE (connection_id, server_id, id),
    CONSTRAINT uq_pbs_datastores_capacity_identity UNIQUE (connection_id, server_id, id, backend_type),
    CONSTRAINT fk_pbs_datastores_server FOREIGN KEY (connection_id, server_id)
        REFERENCES pbs_servers (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_datastores_first_run FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_datastores_last_run FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_datastores_backend CHECK (backend_type IN ('filesystem', 's3')),
    CONSTRAINT chk_pbs_datastores_mount CHECK (mount_status IN ('mounted', 'notmounted', 'nonremovable')),
    CONSTRAINT chk_pbs_datastores_maintenance CHECK (
        maintenance_mode IS NULL OR maintenance_mode IN ('read-only', 'offline', 'delete', 'unmount', 's3-refresh')
    ),
    CONSTRAINT chk_pbs_datastores_writes CHECK (
        allows_backup_writes IN (0, 1)
        AND allows_backup_writes = (mount_status <> 'notmounted' AND maintenance_mode IS NULL)
    ),
    CONSTRAINT chk_pbs_datastores_state CHECK (inventory_state IN ('active', 'archived')),
    CONSTRAINT chk_pbs_datastores_archive CHECK (
        (inventory_state = 'active' AND archived_at IS NULL)
        OR (inventory_state = 'archived' AND archived_at IS NOT NULL)
    ),
    CONSTRAINT chk_pbs_datastores_seen_time CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_datastore_capacity_state (
    datastore_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    server_id BINARY(16) NOT NULL,
    backend_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    semantics VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    total_bytes BIGINT UNSIGNED NOT NULL,
    used_bytes BIGINT UNSIGNED NOT NULL,
    available_bytes BIGINT UNSIGNED NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    sync_run_id BINARY(16) NOT NULL,
    PRIMARY KEY (datastore_id),
    CONSTRAINT fk_pbs_capacity_datastore FOREIGN KEY (connection_id, server_id, datastore_id, backend_type)
        REFERENCES pbs_datastores (connection_id, server_id, id, backend_type) ON DELETE CASCADE,
    CONSTRAINT fk_pbs_capacity_run FOREIGN KEY (connection_id, sync_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_capacity_semantics CHECK (
        (backend_type = 'filesystem' AND semantics = 'datastore_filesystem')
        OR (backend_type = 's3' AND semantics = 'local_cache')
    ),
    CONSTRAINT chk_pbs_capacity_values CHECK (
        used_bytes <= total_bytes AND available_bytes <= total_bytes
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_servers');
        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_server_status');
        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_datastores');
        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE, DELETE', 'pbs_datastore_capacity_state');
    }

    public function down(Schema $schema): void
    {
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE, DELETE', 'pbs_datastore_capacity_state');
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_datastores');
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_server_status');
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pbs_servers');

        $this->addSql('DROP TABLE pbs_datastore_capacity_state');
        $this->addSql('DROP TABLE pbs_datastores');
        $this->addSql('DROP TABLE pbs_server_status');
        $this->addSql('DROP TABLE pbs_servers');

        $this->addSql(<<<'SQL'
ALTER TABLE inventory_sync_scope_results
    DROP CONSTRAINT chk_inventory_sync_scope_type,
    ADD CONSTRAINT chk_inventory_sync_scope_type CHECK (
        scope_type IN ('pve_topology', 'pve_guests', 'pve_storages', 'pve_node_storages')
    )
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE proxmox_installation_bindings
    DROP CONSTRAINT chk_proxmox_installation_bindings_identity,
    DROP FOREIGN KEY fk_proxmox_installation_bindings_legacy_endpoint,
    DROP COLUMN legacy_endpoint_id,
    ADD CONSTRAINT chk_proxmox_installation_bindings_identity CHECK (
        OCTET_LENGTH(identity_value) BETWEEN 1 AND 255
        AND (
            (product = 'pve' AND identity_kind IN ('pve_cluster', 'pve_standalone'))
            OR (product = 'pbs' AND identity_kind IN ('pbs_instance', 'pbs_node'))
        )
    )
SQL);
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
            throw new \RuntimeException('A PBS runtime grant identifier is invalid.');
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
