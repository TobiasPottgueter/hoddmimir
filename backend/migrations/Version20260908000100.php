<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260908000100 extends AbstractMigration
{
    public function getDescription(): string { return 'Add idempotent queue history with bounded retention and target aggregates.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE queue_metric_ticks (observed_at DATETIME(6) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        // Scope zero is the global series. Target identifiers intentionally survive target deletion.
        $this->addSql(<<<'SQL'
CREATE TABLE queue_metric_samples (
    scope_id BINARY(16) NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    waiting_count BIGINT UNSIGNED NOT NULL,
    active_count BIGINT UNSIGNED NOT NULL,
    unresolved_count BIGINT UNSIGNED NOT NULL,
    oldest_wait_seconds BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (scope_id, observed_at),
    INDEX idx_queue_metric_time (observed_at),
    CONSTRAINT fk_queue_metric_tick FOREIGN KEY (observed_at) REFERENCES queue_metric_ticks (observed_at) ON DELETE CASCADE
) ENGINE=InnoDB
SQL);
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) throw new RuntimeException('Invalid queue metric database.');
        foreach (['queue_metric_ticks', 'queue_metric_samples'] as $table) {
            $this->addSql(sprintf("GRANT SELECT, INSERT, DELETE ON `%s`.`%s` TO 'hoddmimir_collector'@'%%'", $database, $table));
            $this->addSql(sprintf("GRANT SELECT ON `%s`.`%s` TO 'hoddmimir_web'@'%%'", $database, $table));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE queue_metric_samples');
        $this->addSql('DROP TABLE queue_metric_ticks');
    }
}
