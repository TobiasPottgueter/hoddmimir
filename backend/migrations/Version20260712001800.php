<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260712001800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable PVE failure-notification recipients to backup policies.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE backup_policies
    ADD failure_notification_recipients_json LONGTEXT NOT NULL DEFAULT ('[]'),
    ADD CONSTRAINT chk_backup_policies_failure_recipients CHECK (
        JSON_VALID(failure_notification_recipients_json)
        AND JSON_TYPE(failure_notification_recipients_json) = 'ARRAY'
        AND JSON_LENGTH(failure_notification_recipients_json) <= 32
    )
SQL);
        $this->privilege('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privilege('REVOKE');
        $this->addSql('ALTER TABLE backup_policies DROP CONSTRAINT chk_backup_policies_failure_recipients, DROP COLUMN failure_notification_recipients_json');
    }

    private function privilege(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database) || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new RuntimeException('Invalid policy-recipient grant identifier.');
        }
        $this->addSql(sprintf(
            "%s UPDATE (failure_notification_recipients_json) ON `%s`.`backup_policies` %s 'hoddmimir_web'@'%%'",
            $operation,
            $database,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
        $this->addSql(sprintf(
            "%s SELECT (connection_id, product, version_major, version_minor, version_patch, raw_version, last_observed_at, id) ON `%s`.`proxmox_capability_snapshots` %s 'hoddmimir_web'@'%%'",
            $operation,
            $database,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
    }
}
