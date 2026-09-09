<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grant the WebApp only the inserts required for first-admin bootstrap.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->privilege('GRANT', 'INSERT', 'users');
        $this->privilege('GRANT', 'INSERT', 'user_roles');
    }

    public function down(Schema $schema): void
    {
        $this->privilege('REVOKE', 'INSERT', 'user_roles');
        $this->privilege('REVOKE', 'INSERT', 'users');
    }

    private function privilege(string $operation, string $privileges, string $table): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new \RuntimeException('The auth-bootstrap grant database is invalid.');
        }
        $this->addSql(sprintf(
            "%s %s ON `%s`.`%s` %s 'hoddmimir_web'@'%%'",
            $operation,
            $privileges,
            $database,
            $table,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
    }
}
