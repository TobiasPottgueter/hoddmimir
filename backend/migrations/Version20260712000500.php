<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000500 extends AbstractMigration
{
    /** @var array<string, list<string>> */
    private const array COLUMNS = [
        'pve_storages' => ['node_allowlist_json'],
        'pve_node_storage_state' => [
            'node_id', 'storage_id', 'enabled', 'active', 'capacity_status',
            'total_bytes', 'used_bytes', 'available_bytes', 'observed_at',
        ],
        'proxmox_connection_endpoints' => ['id', 'connection_id', 'host', 'port', 'enabled'],
    ];

    public function getDescription(): string
    {
        return 'Grant the WebApp the exact read-only evidence columns for backup-target candidates.';
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
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new \RuntimeException('The target-candidate grant database is invalid.');
        }
        foreach (self::COLUMNS as $table => $columns) {
            $this->addSql(sprintf(
                "%s SELECT (%s) ON `%s`.`%s` %s 'hoddmimir_web'@'%%'",
                $operation,
                implode(', ', $columns),
                $database,
                $table,
                'GRANT' === $operation ? 'TO' : 'FROM',
            ));
        }
    }
}
