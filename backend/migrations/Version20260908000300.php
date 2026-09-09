<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908000300 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist bounded, redacted PBS task inspections from fenced collector cycles.'; }
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pbs_observed_tasks ADD inspection_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, ADD inspected_at DATETIME(6) NULL, ADD CONSTRAINT chk_pbs_observed_tasks_inspection CHECK (inspection_json IS NULL OR JSON_VALID(inspection_json))');
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) throw new \RuntimeException('Invalid PBS task database.');
        $this->addSql(sprintf("GRANT SELECT ON `%s`.`pbs_observed_tasks` TO 'hoddmimir_web'@'%%'", $database));
    }
    public function down(Schema $schema): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) throw new \RuntimeException('Invalid PBS task database.');
        $this->addSql(sprintf("REVOKE SELECT ON `%s`.`pbs_observed_tasks` FROM 'hoddmimir_web'@'%%'", $database));
        $this->addSql('ALTER TABLE pbs_observed_tasks DROP CONSTRAINT chk_pbs_observed_tasks_inspection, DROP inspection_json, DROP inspected_at');
    }
}
