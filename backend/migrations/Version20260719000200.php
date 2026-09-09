<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260719000200 extends AbstractMigration
{
    /** @var array<string, list<string>> */
    private const array SELECT_COLUMNS = [
        'proxmox_connections' => ['id', 'product', 'enabled'],
        'proxmox_connection_endpoints' => ['connection_id', 'host', 'port', 'enabled'],
        'proxmox_capability_snapshots' => ['id', 'connection_id', 'product', 'version_major', 'last_observed_at'],
        'pve_clusters' => ['id', 'connection_id', 'inventory_state', 'last_seen_at'],
        'pve_nodes' => ['id', 'connection_id', 'cluster_id', 'node_name', 'api_status', 'inventory_state', 'last_seen_at'],
        'guests' => [
            'id', 'connection_id', 'cluster_id', 'name', 'guest_type', 'vmid', 'is_template',
            'provisioned_size_bytes', 'inventory_state', 'last_seen_at',
        ],
        'guest_placements' => ['guest_id', 'connection_id', 'cluster_id', 'node_id', 'placement_revision', 'observed_at'],
        'pve_storages' => [
            'id', 'connection_id', 'cluster_id', 'storage_name', 'storage_type', 'supports_backup',
            'disabled', 'inventory_state', 'last_seen_at',
        ],
        'pve_node_storage_state' => ['node_id', 'storage_id', 'enabled', 'active', 'available_bytes', 'observed_at'],
        'pve_storage_pbs_mappings' => ['storage_id', 'server', 'port', 'datastore', 'namespace', 'observed_at'],
        'pbs_datastores' => [
            'id', 'connection_id', 'datastore_name', 'allows_backup_writes', 'inventory_state', 'last_seen_at',
        ],
        'pbs_datastore_capacity_state' => ['datastore_id', 'semantics', 'available_bytes', 'observed_at'],
        'pbs_namespaces' => ['id', 'datastore_id', 'namespace_path', 'inventory_state'],
    ];

    public function getDescription(): string
    {
        return 'Grant the backup worker the bounded inventory reads required for claim and submission revalidation.';
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
            throw new RuntimeException('The backup-worker revalidation grant database is invalid.');
        }

        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        foreach (self::SELECT_COLUMNS as $table => $columns) {
            $this->addSql(sprintf(
                "%s SELECT (%s) ON `%s`.`%s` %s 'hoddmimir_backup_worker'@'%%'",
                $operation,
                implode(', ', $columns),
                $database,
                $table,
                $direction,
            ));
        }
    }
}
