<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadFailure;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Infrastructure\Persistence\MariaDb\DbalPveEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\PveTlsMode;

final class DbalPveEndpointReadConfigurationSourceTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-11 10:00:00.000000';

    public function testItLoadsOnlyTheOwnedEnabledEndpointAndCollectorCredentialAtExpectedRevision(): void
    {
        [$connectionId, $endpointId] = $this->insertConfiguration();

        $configuration = $this->source()->load(
            new ConnectionId($connectionId),
            new EndpointId($endpointId),
            3,
        );

        self::assertSame('pve-a.example.test', $configuration->host);
        self::assertSame(8006, $configuration->port);
        self::assertSame(PveTlsMode::SystemCa, $configuration->tls->mode);
    }

    public function testRevisionDriftIsDetectedBeforeAConfigurationCanBeUsed(): void
    {
        [$connectionId, $endpointId] = $this->insertConfiguration();
        $this->connection()->update('proxmox_connections', ['revision' => 4], ['id' => $connectionId]);

        try {
            $this->source()->load(new ConnectionId($connectionId), new EndpointId($endpointId), 3);
            self::fail('Revision drift was accepted.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::ConnectionChanged, $failure->failureCode);
        }
    }

    public function testDisabledForeignAndCredentiallessConfigurationsFailClosed(): void
    {
        [$connectionId, $endpointId] = $this->insertConfiguration();
        $foreignConnection = random_bytes(16);
        $this->insertConnection($foreignConnection, 'Foreign PVE');
        $foreignEndpoint = random_bytes(16);
        $this->insertEndpoint($foreignConnection, $foreignEndpoint, 'pve-foreign.example.test');

        $cases = [
            [$foreignEndpoint, EndpointReadFailureCode::RootUnusable],
        ];
        foreach ($cases as [$candidateEndpoint, $expected]) {
            try {
                $this->source()->load(new ConnectionId($connectionId), new EndpointId($candidateEndpoint), 3);
                self::fail('Foreign endpoint was accepted.');
            } catch (EndpointReadFailure $failure) {
                self::assertSame($expected, $failure->failureCode);
            }
        }

        $this->connection()->update('proxmox_connection_endpoints', ['enabled' => 0], ['id' => $endpointId]);
        try {
            $this->source()->load(new ConnectionId($connectionId), new EndpointId($endpointId), 3);
            self::fail('Disabled endpoint was accepted.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::RootUnusable, $failure->failureCode);
        }

        $this->connection()->update('proxmox_connection_endpoints', ['enabled' => 1], ['id' => $endpointId]);
        $this->connection()->delete('proxmox_credentials', ['connection_id' => $connectionId]);
        try {
            $this->source()->load(new ConnectionId($connectionId), new EndpointId($endpointId), 3);
            self::fail('Credentialless endpoint was accepted.');
        } catch (EndpointReadFailure $failure) {
            self::assertSame(EndpointReadFailureCode::CredentialUnavailable, $failure->failureCode);
        }
    }

    /** @return array{string, string} */
    private function insertConfiguration(): array
    {
        $connectionId = random_bytes(16);
        $endpointId = random_bytes(16);
        $this->insertConnection($connectionId, 'Primary PVE');
        $this->insertEndpoint($connectionId, $endpointId, 'pve-a.example.test');
        $this->connection()->insert('proxmox_credentials', [
            'id' => random_bytes(16),
            'connection_id' => $connectionId,
            'purpose' => 'collector',
            'auth_scheme' => 'api_token',
            'principal' => 'collector@pve',
            'token_name' => 'inventory',
            'secret_envelope' => 'opaque-encrypted-envelope',
            'envelope_version' => 1,
            'key_id' => 'key_1',
            'revision' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return [$connectionId, $endpointId];
    }

    private function insertConnection(string $connectionId, string $displayName): void
    {
        $this->connection()->insert('proxmox_connections', [
            'id' => $connectionId,
            'display_name' => $displayName.' '.bin2hex(random_bytes(3)),
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 3,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function insertEndpoint(string $connectionId, string $endpointId, string $host): void
    {
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $endpointId,
            'connection_id' => $connectionId,
            'host' => $host,
            'port' => 8006,
            'priority' => 100,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function source(): DbalPveEndpointReadConfigurationSource
    {
        return new DbalPveEndpointReadConfigurationSource($this->connection());
    }
}
