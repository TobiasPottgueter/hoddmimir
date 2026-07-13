<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000700 extends AbstractMigration
{
    private const array TABLES = [
        'backup_targets',
        'backup_target_allowed_nodes',
    ];

    private const array READERS = [
        'hoddmimir_collector',
        'hoddmimir_web',
    ];

    public function getDescription(): string
    {
        return 'Add fail-closed disabled backup-target drafts and explicit allowed-node relationships.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE backup_targets (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    storage_id BINARY(16) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'disabled',
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    minimum_free_bytes BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    disabled_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_backup_targets_context_id UNIQUE (connection_id, cluster_id, id),
    INDEX idx_backup_targets_storage (storage_id, id),
    CONSTRAINT fk_backup_targets_storage FOREIGN KEY (connection_id, cluster_id, storage_id)
        REFERENCES pve_storages (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_backup_targets_display_name CHECK (
        CHAR_LENGTH(TRIM(display_name)) BETWEEN 1 AND 190
    ),
    CONSTRAINT chk_backup_targets_status CHECK (status = 'disabled'),
    CONSTRAINT chk_backup_targets_revision CHECK (revision > 0),
    CONSTRAINT chk_backup_targets_time CHECK (
        created_at <= disabled_at AND disabled_at <= updated_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE backup_target_allowed_nodes (
    target_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    node_id BINARY(16) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (target_id, node_id),
    INDEX idx_backup_target_allowed_nodes_node (node_id, target_id),
    CONSTRAINT fk_backup_target_allowed_nodes_target FOREIGN KEY (connection_id, cluster_id, target_id)
        REFERENCES backup_targets (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_backup_target_allowed_nodes_node FOREIGN KEY (connection_id, cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
ALTER TABLE scheduler_decisions
    ADD CONSTRAINT fk_scheduler_decisions_target FOREIGN KEY (connection_id, cluster_id, target_id)
        REFERENCES backup_targets (connection_id, cluster_id, id) ON DELETE RESTRICT
SQL);

        $this->readPrivileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->readPrivileges('REVOKE');
        $this->addSql('ALTER TABLE scheduler_decisions DROP FOREIGN KEY fk_scheduler_decisions_target');
        $this->addSql('DROP TABLE backup_target_allowed_nodes');
        $this->addSql('DROP TABLE backup_targets');
    }

    private function readPrivileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database)
            || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A backup-target grant identifier is invalid.');
        }

        foreach (self::READERS as $reader) {
            foreach (self::TABLES as $table) {
                $this->addSql(sprintf(
                    "%s SELECT ON `%s`.`%s` %s '%s'@'%%'",
                    $operation,
                    $database,
                    $table,
                    'GRANT' === $operation ? 'TO' : 'FROM',
                    $reader,
                ));
            }
        }
    }
}
