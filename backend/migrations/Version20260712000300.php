<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grant the collector its capability snapshot persistence surface.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->privilege('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privilege('REVOKE');
    }

    private function privilege(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A capability grant identifier is invalid.');
        }
        $this->addSql(sprintf(
            "%s SELECT, INSERT, UPDATE ON `%s`.`proxmox_capability_snapshots` %s 'hoddmimir_collector'@'%%'",
            $operation,
            $database,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
    }
}
