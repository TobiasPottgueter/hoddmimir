<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712001100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scheduler guest backup baselines and append-only write-counter reset evidence.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE guest_backup_state (
    guest_id BINARY(16) NOT NULL,
    policy_id BINARY(16) NOT NULL,
    target_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    last_success_at DATETIME(6) NOT NULL,
    last_success_size_bytes BIGINT UNSIGNED NULL,
    baseline_bytes BIGINT UNSIGNED NOT NULL,
    baseline_observed_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (guest_id, policy_id, target_id),
    CONSTRAINT fk_guest_backup_state_guest FOREIGN KEY (connection_id, cluster_id, guest_id)
        REFERENCES guests (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_guest_backup_state_policy FOREIGN KEY (connection_id, cluster_id, policy_id)
        REFERENCES backup_policies (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_guest_backup_state_target FOREIGN KEY (connection_id, cluster_id, target_id)
        REFERENCES backup_targets (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_guest_backup_state_time CHECK (
        last_success_at <= updated_at AND baseline_observed_at <= updated_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE guest_write_counter_resets (
    id BINARY(16) NOT NULL,
    cycle_token BINARY(16) NOT NULL,
    collector_fencing_token BIGINT NOT NULL,
    guest_id BINARY(16) NOT NULL,
    policy_id BINARY(16) NOT NULL,
    target_id BINARY(16) NOT NULL,
    previous_baseline_bytes BIGINT UNSIGNED NOT NULL,
    current_bytes BIGINT UNSIGNED NOT NULL,
    detected_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_guest_write_counter_resets_cycle_tuple UNIQUE (cycle_token, guest_id, policy_id, target_id),
    CONSTRAINT fk_guest_write_counter_resets_cycle FOREIGN KEY (cycle_token, collector_fencing_token)
        REFERENCES collector_cycles (cycle_token, fencing_token) ON DELETE RESTRICT,
    CONSTRAINT fk_guest_write_counter_resets_state FOREIGN KEY (guest_id, policy_id, target_id)
        REFERENCES guest_backup_state (guest_id, policy_id, target_id) ON DELETE RESTRICT,
    CONSTRAINT chk_guest_write_counter_resets_fence CHECK (collector_fencing_token > 0),
    CONSTRAINT chk_guest_write_counter_resets_counter CHECK (current_bytes < previous_baseline_bytes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        $this->addSql('DROP TABLE guest_write_counter_resets');
        $this->addSql('DROP TABLE guest_backup_state');
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new \RuntimeException('The shadow baseline grant database is invalid.');
        }
        foreach (['guest_backup_state', 'guest_write_counter_resets'] as $table) {
            $privileges = 'guest_backup_state' === $table ? 'SELECT, UPDATE' : 'SELECT, INSERT';
            $this->addSql(sprintf("%s %s ON `%s`.`%s` %s 'hoddmimir_collector'@'%%'", $operation, $privileges, $database, $table, 'GRANT' === $operation ? 'TO' : 'FROM'));
            $this->addSql(sprintf("%s SELECT ON `%s`.`%s` %s 'hoddmimir_web'@'%%'", $operation, $database, $table, 'GRANT' === $operation ? 'TO' : 'FROM'));
        }
        foreach (['scheduler_evaluation_runs', 'scheduler_decisions', 'scheduler_decision_gates'] as $table) {
            $this->addSql(sprintf("%s SELECT ON `%s`.`%s` %s 'hoddmimir_web'@'%%'", $operation, $database, $table, 'GRANT' === $operation ? 'TO' : 'FROM'));
        }
        $this->addSql(sprintf("%s SELECT, INSERT, UPDATE ON `%s`.`guest_backup_state` %s 'hoddmimir_backup_worker'@'%%'", $operation, $database, 'GRANT' === $operation ? 'TO' : 'FROM'));
        $this->addSql(sprintf("%s SELECT ON `%s`.`guest_write_counter_resets` %s 'hoddmimir_backup_worker'@'%%'", $operation, $database, 'GRANT' === $operation ? 'TO' : 'FROM'));
    }
}
