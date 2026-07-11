<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\InstallationBindingCatalog;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalInstallationBindingCatalog implements InstallationBindingCatalog
{
    public function __construct(private Connection $connection)
    {
    }

    public function bindingFor(ConnectionId $connectionId): ?InstallationBinding
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT product, identity_kind, identity_value
                FROM proxmox_installation_bindings
                WHERE connection_id = :connection_id
                SQL,
            ['connection_id' => $connectionId->bytes],
        );
        if (false === $row) {
            return null;
        }
        $product = $row['product'] ?? null;
        $kind = $row['identity_kind'] ?? null;
        $identity = $row['identity_value'] ?? null;
        if (!is_string($product) || !is_string($kind) || !is_string($identity)) {
            throw new RuntimeException('MariaDB returned an invalid installation binding.');
        }

        if ('pve' === $product && 'pve_cluster' === $kind) {
            $members = $this->connection->fetchFirstColumn(
                'SELECT node_name FROM pve_nodes WHERE connection_id = :connection_id ORDER BY node_name',
                ['connection_id' => $connectionId->bytes],
            );
            $names = [];
            foreach ($members as $member) {
                if (!is_string($member)) {
                    throw new RuntimeException('MariaDB returned an invalid PVE member node.');
                }
                $names[] = $member;
            }
            if ([] === $names) {
                throw new RuntimeException('A clustered PVE binding has no persisted member evidence.');
            }
            return InstallationBinding::pveCluster($identity, $names);
        }
        if ('pve' === $product && 'pve_standalone' === $kind) {
            return InstallationBinding::pveStandalone($identity);
        }
        if ('pbs' === $product && 'pbs_instance' === $kind) {
            return InstallationBinding::pbs4Instance($identity);
        }
        if ('pbs' === $product && 'pbs_node' === $kind) {
            return InstallationBinding::pbs3Node($identity);
        }

        throw new RuntimeException('MariaDB returned an unsupported installation binding.');
    }
}
