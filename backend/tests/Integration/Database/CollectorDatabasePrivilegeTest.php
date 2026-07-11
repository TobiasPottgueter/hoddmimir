<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\EndpointId;
use App\Infrastructure\Persistence\MariaDb\DbalPveEndpointReadConfigurationSource;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class CollectorDatabasePrivilegeTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-11 10:00:00.000000';

    public function testCollectorCanReadOnlyItsCredentialViewAndCannotMutateConfiguration(): void
    {
        $connectionId = random_bytes(16);
        $endpointId = random_bytes(16);
        $this->seedBothCredentialPurposes($connectionId, $endpointId);
        $this->connection()->commit();

        $collector = $this->collectorConnection();
        try {
            self::assertSame(['collector'], $collector->fetchFirstColumn(
                'SELECT purpose FROM collector_credentials WHERE connection_id = :connection_id',
                ['connection_id' => $connectionId],
            ));
            $configuration = (new DbalPveEndpointReadConfigurationSource($collector))->load(
                new ConnectionId($connectionId),
                new EndpointId($endpointId),
                1,
            );
            self::assertSame('collector-pve.example.test', $configuration->host);

            $this->assertDenied(
                static fn () => $collector->fetchAllAssociative('SELECT * FROM proxmox_credentials'),
            );
            $this->assertDenied(static fn () => $collector->executeStatement(
                'UPDATE proxmox_connections SET enabled = 0 WHERE id = :connection_id',
                ['connection_id' => $connectionId],
            ));
            $this->assertDenied(static fn () => $collector->executeStatement(
                'DELETE FROM pve_nodes WHERE connection_id = :connection_id',
                ['connection_id' => $connectionId],
            ));
        } finally {
            $collector->close();
            $this->connection()->delete('proxmox_connections', ['id' => $connectionId]);
        }
    }

    private function seedBothCredentialPurposes(string $connectionId, string $endpointId): void
    {
        $this->connection()->insert('proxmox_connections', [
            'id' => $connectionId,
            'display_name' => 'Collector privilege test '.bin2hex(random_bytes(4)),
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $endpointId,
            'connection_id' => $connectionId,
            'host' => 'collector-pve.example.test',
            'port' => 8006,
            'priority' => 1,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach (['collector', 'backup'] as $purpose) {
            $this->connection()->insert('proxmox_credentials', [
                'id' => random_bytes(16),
                'connection_id' => $connectionId,
                'purpose' => $purpose,
                'auth_scheme' => 'api_token',
                'principal' => $purpose.'@pve',
                'token_name' => 'inventory',
                'secret_envelope' => 'opaque-encrypted-envelope-'.$purpose,
                'envelope_version' => 1,
                'key_id' => 'key_1',
                'revision' => 1,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
    }

    private function collectorConnection(): Connection
    {
        $password = file_get_contents('/run/secrets/mariadb_collector_password');
        self::assertIsString($password);

        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => 'hoddmimir_collector',
            'password' => trim($password),
        ]));
    }

    /** @param callable(): mixed $operation */
    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('The collector database user exceeded its production grants.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
