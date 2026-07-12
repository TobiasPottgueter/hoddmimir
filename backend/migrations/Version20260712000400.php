<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add monotone guest placement revisions and authoritative raw PVE disk-write state.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE guest_placements
                ADD placement_revision BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER node_id,
                ADD CONSTRAINT chk_guest_placements_revision CHECK (placement_revision >= 1)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE guest_write_states (
                guest_id BINARY(16) NOT NULL,
                connection_id BINARY(16) NOT NULL,
                cluster_id BINARY(16) NOT NULL,
                diskwrite_bytes BIGINT UNSIGNED NOT NULL,
                observed_at DATETIME(6) NOT NULL,
                authoritative_sync_run_id BINARY(16) NOT NULL,
                PRIMARY KEY (guest_id),
                INDEX idx_guest_write_states_run (connection_id, authoritative_sync_run_id),
                CONSTRAINT fk_guest_write_states_guest FOREIGN KEY (connection_id, cluster_id, guest_id)
                    REFERENCES guests (connection_id, cluster_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_guest_write_states_run FOREIGN KEY (connection_id, authoritative_sync_run_id)
                    REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->collectorPrivilege('GRANT');
        $this->placementDeletePrivilege('REVOKE');
    }

    public function down(Schema $schema): void
    {
        $this->collectorPrivilege('REVOKE');
        $this->placementDeletePrivilege('GRANT');
        $this->addSql('DROP TABLE guest_write_states');
        $this->addSql('ALTER TABLE guest_placements DROP CONSTRAINT chk_guest_placements_revision, DROP placement_revision');
    }

    private function collectorPrivilege(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database)
            || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A guest-state grant identifier is invalid.');
        }

        $this->addSql(sprintf(
            "%s SELECT, INSERT, UPDATE ON `%s`.`guest_write_states` %s 'hoddmimir_collector'@'%%'",
            $operation,
            $database,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
    }

    private function placementDeletePrivilege(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database)
            || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A guest-placement grant identifier is invalid.');
        }

        $this->addSql(sprintf(
            "%s DELETE ON `%s`.`guest_placements` %s 'hoddmimir_collector'@'%%'",
            $operation,
            $database,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
    }
}
