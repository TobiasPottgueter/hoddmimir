<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionScanCatalog;
use App\Application\Inventory\Connection\ConnectionScanTarget;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointScanReference;
use App\Application\Inventory\Connection\ProxmoxProduct;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalConnectionScanCatalog implements ConnectionScanCatalog
{
    public function __construct(private Connection $connection)
    {
    }

    public function enabledTargets(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    c.id AS connection_id,
                    c.product,
                    c.revision,
                    e.id AS endpoint_id,
                    e.priority
                FROM proxmox_connections c
                LEFT JOIN proxmox_connection_endpoints e
                    ON e.connection_id = c.id AND e.enabled = 1
                WHERE c.enabled = 1
                ORDER BY c.id, e.priority, e.id
                SQL,
        );

        /** @var array<string, array{connection_id: string, product: ProxmoxProduct, revision: int, endpoints: list<EndpointScanReference>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $connectionId = $this->binary($row, 'connection_id');
            $key = bin2hex($connectionId);
            $productValue = $row['product'] ?? null;
            if (!is_string($productValue)) {
                throw new RuntimeException('MariaDB returned an invalid connection product.');
            }
            $product = ProxmoxProduct::tryFrom($productValue);
            if (null === $product) {
                throw new RuntimeException('MariaDB returned an unsupported connection product.');
            }
            $revision = $this->integer($row, 'revision');
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'connection_id' => $connectionId,
                    'product' => $product,
                    'revision' => $revision,
                    'endpoints' => [],
                ];
            } elseif ($grouped[$key]['product'] !== $product || $grouped[$key]['revision'] !== $revision) {
                throw new RuntimeException('MariaDB returned inconsistent connection scan rows.');
            }
            if (null !== ($row['endpoint_id'] ?? null)) {
                $grouped[$key]['endpoints'][] = new EndpointScanReference(
                    new EndpointId($this->binary($row, 'endpoint_id')),
                    $this->integer($row, 'priority'),
                );
            }
        }

        $targets = [];
        foreach ($grouped as $connection) {
            $targets[] = new ConnectionScanTarget(
                new ConnectionId($connection['connection_id']),
                $connection['revision'],
                $connection['product'],
                $connection['endpoints'],
            );
        }

        return $targets;
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid scan identifier.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new RuntimeException('MariaDB returned an invalid scan integer.');
    }
}
