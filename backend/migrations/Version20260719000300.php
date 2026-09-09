<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260719000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grant the backup worker the bounded guest write-state read required to persist a successful backup baseline.';
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
        if (!is_string($database)
            || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new RuntimeException('The backup-worker success-baseline grant database is invalid.');
        }

        $this->addSql(sprintf(
            "%s SELECT (guest_id, diskwrite_bytes, observed_at) ON `%s`.`guest_write_states` %s 'hoddmimir_backup_worker'@'%%'",
            $operation,
            $database,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
    }
}
