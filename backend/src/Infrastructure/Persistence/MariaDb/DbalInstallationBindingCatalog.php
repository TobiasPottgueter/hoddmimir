<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\InstallationBindingCatalog;
use App\Application\Proxmox\Pbs\PbsNodeRoute;
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
                SELECT product, identity_kind, identity_value, legacy_endpoint_id
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
            if (null !== ($row['legacy_endpoint_id'] ?? null)) {
                throw new RuntimeException('A PBS instance binding unexpectedly carries a legacy endpoint.');
            }
            return InstallationBinding::pbsInstance($identity);
        }
        if ('pbs' === $product && 'pbs_legacy_node' === $kind) {
            $endpoint = $row['legacy_endpoint_id'] ?? null;
            if (!is_string($endpoint) || 16 !== strlen($endpoint)) {
                throw new RuntimeException('A legacy PBS binding has no valid endpoint.');
            }
            if (!hash_equals(PbsNodeRoute::Local->value, $identity)) {
                throw new RuntimeException('A legacy PBS binding has a non-canonical identity.');
            }
            return InstallationBinding::pbsLegacyEndpoint(new \App\Application\Inventory\Connection\EndpointId($endpoint));
        }

        throw new RuntimeException('MariaDB returned an unsupported installation binding.');
    }
}
