<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add atomic PVE storage inventory persistence, scoped diagnostics, and collector privileges.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE inventory_sync_scope_results
    DROP CONSTRAINT chk_inventory_sync_scope_type,
    ADD CONSTRAINT chk_inventory_sync_scope_type CHECK (
        scope_type IN ('pve_topology', 'pve_guests', 'pve_storages', 'pve_node_storages')
    )
SQL);

        $this->addSql(<<<'SQL'
ALTER TABLE pve_storages
    ADD COLUMN disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER supports_backup,
    ADD COLUMN content_json LONGTEXT NULL AFTER disabled,
    ADD COLUMN node_allowlist_json LONGTEXT NULL AFTER content_json,
    ADD COLUMN first_seen_at DATETIME(6) NULL AFTER last_seen_run_id,
    ADD COLUMN last_seen_at DATETIME(6) NULL AFTER first_seen_at
SQL);
        $this->addSql(<<<'SQL'
UPDATE pve_storages AS storage
JOIN inventory_sync_runs AS first_run ON first_run.id = storage.first_seen_run_id
JOIN inventory_sync_runs AS last_run ON last_run.id = storage.last_seen_run_id
SET storage.content_json = IF(storage.supports_backup = 1, JSON_ARRAY('backup'), JSON_ARRAY('unknown')),
    storage.first_seen_at = first_run.finished_at,
    storage.last_seen_at = last_run.finished_at
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE pve_storages
    MODIFY content_json LONGTEXT NOT NULL,
    MODIFY first_seen_at DATETIME(6) NOT NULL,
    MODIFY last_seen_at DATETIME(6) NOT NULL,
    ADD CONSTRAINT chk_pve_storages_disabled CHECK (disabled IN (0, 1)),
    ADD CONSTRAINT chk_pve_storages_content CHECK (
        JSON_VALID(content_json)
        AND JSON_TYPE(content_json) = 'ARRAY'
        AND JSON_LENGTH(content_json) > 0
    ),
    ADD CONSTRAINT chk_pve_storages_node_allowlist CHECK (
        node_allowlist_json IS NULL
        OR (JSON_VALID(node_allowlist_json)
            AND JSON_TYPE(node_allowlist_json) = 'ARRAY'
            AND JSON_LENGTH(node_allowlist_json) > 0)
    ),
    ADD CONSTRAINT chk_pve_storages_seen_time CHECK (last_seen_at >= first_seen_at)
SQL);

        $this->addSql(<<<'SQL'
ALTER TABLE pve_node_storage_state
    ADD COLUMN shared TINYINT(1) NOT NULL DEFAULT 0 AFTER active,
    ADD COLUMN capacity_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER shared,
    DROP CONSTRAINT chk_pve_node_storage_capacity
SQL);
        $this->addSql(<<<'SQL'
UPDATE pve_node_storage_state
SET capacity_status = CASE
    WHEN total_bytes IS NULL AND used_bytes IS NULL AND available_bytes IS NULL THEN 'unavailable'
    ELSE 'measured'
END
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE pve_node_storage_state
    MODIFY capacity_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD CONSTRAINT chk_pve_node_storage_shared CHECK (shared IN (0, 1)),
    ADD CONSTRAINT chk_pve_node_storage_capacity CHECK (
        (capacity_status = 'measured'
            AND total_bytes IS NOT NULL AND used_bytes IS NOT NULL AND available_bytes IS NOT NULL
            AND used_bytes <= total_bytes AND available_bytes <= total_bytes)
        OR (capacity_status IN ('unavailable', 'invalid')
            AND total_bytes IS NULL AND used_bytes IS NULL AND available_bytes IS NULL)
    )
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pve_storage_pbs_mappings (
    storage_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    server VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    datastore VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    namespace VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    observed_at DATETIME(6) NOT NULL,
    sync_run_id BINARY(16) NOT NULL,
    PRIMARY KEY (storage_id),
    CONSTRAINT fk_pve_storage_pbs_mapping_storage FOREIGN KEY (connection_id, cluster_id, storage_id)
        REFERENCES pve_storages (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pve_storage_pbs_mapping_run FOREIGN KEY (connection_id, sync_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pve_storage_pbs_mapping_server CHECK (
        OCTET_LENGTH(server) BETWEEN 1 AND 255 AND server REGEXP '^[[:graph:]]+$'
    ),
    CONSTRAINT chk_pve_storage_pbs_mapping_port CHECK (port BETWEEN 1 AND 65535),
    CONSTRAINT chk_pve_storage_pbs_mapping_datastore CHECK (
        OCTET_LENGTH(datastore) BETWEEN 1 AND 190 AND datastore REGEXP '^[A-Za-z0-9][A-Za-z0-9._-]*$'
    ),
    CONSTRAINT chk_pve_storage_pbs_mapping_namespace CHECK (
        namespace IS NULL OR (
            OCTET_LENGTH(namespace) BETWEEN 1 AND 255 AND namespace REGEXP '^[[:graph:]]+$'
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pve_storages');
        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE, DELETE', 'pve_node_storage_state');
        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE, DELETE', 'pve_storage_pbs_mappings');
    }

    public function down(Schema $schema): void
    {
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE, DELETE', 'pve_storage_pbs_mappings');
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE, DELETE', 'pve_node_storage_state');
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', 'pve_storages');

        $this->addSql('DROP TABLE pve_storage_pbs_mappings');
        $this->addSql(<<<'SQL'
ALTER TABLE pve_node_storage_state
    DROP CONSTRAINT chk_pve_node_storage_capacity,
    DROP CONSTRAINT chk_pve_node_storage_shared,
    DROP COLUMN capacity_status,
    DROP COLUMN shared,
    ADD CONSTRAINT chk_pve_node_storage_capacity CHECK (
        (total_bytes IS NULL AND used_bytes IS NULL AND available_bytes IS NULL)
        OR (total_bytes IS NOT NULL AND used_bytes IS NOT NULL AND available_bytes IS NOT NULL
            AND used_bytes <= total_bytes AND available_bytes <= total_bytes)
    )
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE pve_storages
    DROP CONSTRAINT chk_pve_storages_seen_time,
    DROP CONSTRAINT chk_pve_storages_node_allowlist,
    DROP CONSTRAINT chk_pve_storages_content,
    DROP CONSTRAINT chk_pve_storages_disabled,
    DROP COLUMN last_seen_at,
    DROP COLUMN first_seen_at,
    DROP COLUMN node_allowlist_json,
    DROP COLUMN content_json,
    DROP COLUMN disabled
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE inventory_sync_scope_results
    DROP CONSTRAINT chk_inventory_sync_scope_type,
    ADD CONSTRAINT chk_inventory_sync_scope_type CHECK (scope_type IN ('pve_topology', 'pve_guests'))
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
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new \RuntimeException('The migration database name is unsafe for runtime grants.');
        }
        if (1 !== preg_match('/^[a-z_]+$/D', $user) || 1 !== preg_match('/^[a-z_]+$/D', $table)) {
            throw new \RuntimeException('A runtime grant identifier is invalid.');
        }

        $this->addSql(sprintf(
            "%s %s %s `%s`.`%s` %s '%s'@'%%'",
            $operation,
            $privileges,
            'ON',
            $database,
            $table,
            'GRANT' === $operation ? 'TO' : 'FROM',
            $user,
        ));
    }
}
