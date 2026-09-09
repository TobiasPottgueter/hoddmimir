<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260719000100 extends AbstractMigration
{
    private const array TABLES = [
        'backup_node_slots',
        'backup_target_slots',
    ];

    public function getDescription(): string
    {
        return 'Grant the collector read-only access to backup concurrency slots for automatic shadow evaluation.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database)
            || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new RuntimeException('The automatic-shadow concurrency grant database is invalid.');
        }

        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf(
                "%s SELECT ON `%s`.`%s` %s 'hoddmimir_collector'@'%%'",
                $operation,
                $database,
                $table,
                $direction,
            ));
        }
    }
}
