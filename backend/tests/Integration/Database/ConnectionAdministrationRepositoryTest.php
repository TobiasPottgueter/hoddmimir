<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\PlaintextSecret;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use App\Infrastructure\Persistence\MariaDb\DbalConnectionAdministration;

final class ConnectionAdministrationRepositoryTest extends DatabaseTestCase
{
    private const string USER = 'connection-user!';
    private const string CONNECTION = 'connection-id-01';
    private const string ENDPOINT = 'endpoint-id-0001';
    private const string CREDENTIAL = 'credential-id-01';

    public function testConnectionReadModelNeverReturnsPersistedCredentialSecrets(): void
    {
        $this->seedUser();
        $repository = self::getContainer()->get(DbalConnectionAdministration::class);
        self::assertInstanceOf(DbalConnectionAdministration::class, $repository);
        $this->seedConnection();
        $this->connection()->insert('proxmox_credentials', [
            'id' => self::CREDENTIAL,
            'connection_id' => self::CONNECTION,
            'purpose' => 'collector',
            'auth_scheme' => 'api_token',
            'principal' => 'hoddmimir@pve',
            'token_name' => 'collector',
            'secret_envelope' => 'PERSISTED-SECRET-ENVELOPE',
            'secret_verification_hash' => '$argon2id$dummy',
            'envelope_version' => 1,
            'key_id' => 'primary',
            'revision' => 1,
            'created_at' => '2026-07-13 00:00:00.000000',
            'rotated_at' => '2026-07-13 00:00:00.000000',
            'updated_at' => '2026-07-13 00:00:00.000000',
        ]);
        $this->connection()->insert('proxmox_capability_snapshots', [
            'id' => 'capability-id-01', 'connection_id' => self::CONNECTION, 'endpoint_id' => self::ENDPOINT,
            'product' => 'pve', 'version_major' => 8, 'version_minor' => 4, 'version_patch' => 1,
            'release_name' => null, 'raw_version' => '8.4.1', 'profile_version' => 1,
            'capabilities_json' => '{}', 'snapshot_hash' => hash('sha256', 'pve-8.4.1', true),
            'first_observed_at' => '2026-07-13 00:00:00.000000', 'last_observed_at' => '2026-07-13 00:00:00.000000',
        ]);
        $this->connection()->insert('proxmox_connection_onboarding_state', [
            'connection_id' => self::CONNECTION,
            'state' => 'inventory_partial',
            'tls_verified' => 1,
            'product_supported' => 1,
            'scan_permissions_verified' => 1,
            'backup_permissions_verified' => 1,
            'detected_product' => 'pve',
            'detected_version' => '8.4.1',
            'warnings_json' => '[]',
            'verified_at' => '2026-07-13 00:00:00.000000',
            'inventory_status_changed_at' => '2026-07-13 00:02:00.000000',
            'last_inventory_run_id' => 'inventory-run-01',
        ]);
        $detail = $repository->connection($this->uuid(self::CONNECTION));
        self::assertIsArray($detail);
        self::assertSame('PVE', $detail['displayName']);
        self::assertSame('8.4.1', $detail['detectedVersion']);
        self::assertSame('supported', $detail['versionSupportStatus']);
        self::assertSame([
            'status' => 'inventory_partial',
            'verifiedAt' => '2026-07-13T00:00:00.000000Z',
            'inventoryStatusChangedAt' => '2026-07-13T00:02:00.000000Z',
            'lastInventoryRunId' => $this->uuid('inventory-run-01'),
        ], $detail['onboardingState']);
        $endpoints = $detail['endpoints'];
        $credentials = $detail['credentials'];
        self::assertIsArray($endpoints);
        self::assertIsArray($credentials);
        $endpoint = $endpoints[0] ?? null;
        $credential = $credentials[0] ?? null;
        self::assertIsArray($endpoint);
        self::assertIsArray($credential);
        self::assertSame('system_ca', $endpoint['tlsMode']);
        self::assertTrue($credential['configured']);
        self::assertArrayNotHasKey('tokenSecret', $credential);
        self::assertArrayNotHasKey('secretEnvelope', $credential);
        self::assertArrayNotHasKey('secretVerificationHash', $credential);
        self::assertStringNotContainsString('PERSISTED-SECRET-ENVELOPE', json_encode($detail, JSON_THROW_ON_ERROR));
    }

    public function testEveryUnverifiedLegacyProvisioningCommandIsBlockedWithoutPersistence(): void
    {
        $this->seedUser();
        $repository = self::getContainer()->get(DbalConnectionAdministration::class);
        self::assertInstanceOf(DbalConnectionAdministration::class, $repository);
        $sessionId = $this->seedWebSession(self::USER);
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('admin'), [Permission::BackupConfigurationManage], $sessionId);
        $create = new ConfigurationCommand(ConfigurationCommandType::ConnectionCreate, self::CONNECTION, 0, 'connection-create', str_repeat('r', 16), [
            'displayName' => 'PVE Production', 'product' => 'pve',
            'endpoint' => ['id'=>self::ENDPOINT,'host'=>'pve.example.test','port'=>8006,'priority'=>100,'tlsMode'=>'system_ca','customCaPem'=>null,'sha256Fingerprint'=>null],
            'credential' => ['id'=>self::CREDENTIAL,'purpose'=>'collector','principal'=>'hoddmimir@pve','tokenName'=>'collector'],
        ], PlaintextSecret::fromString('high-entropy-token-secret-value'));
        $createResult = $repository->execute($create, $principal);
        self::assertSame(ConfigurationCommandStatus::Blocked, $createResult->status);
        self::assertSame(['verified_onboarding_required'], $createResult->blockers);
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));

        $this->seedConnection();

        $commands = [new ConfigurationCommand(ConfigurationCommandType::EndpointCreate, self::CONNECTION, 1, 'endpoint-create', str_repeat('e', 16), [
            'host'=>'pve-b.example.test','port'=>8006,'priority'=>200,'tlsMode'=>'sha256_fingerprint','customCaPem'=>null,'sha256Fingerprint'=>str_repeat('a',64),
        ]), new ConfigurationCommand(ConfigurationCommandType::EndpointUpdate, self::CONNECTION, 1, 'endpoint-update', str_repeat('u', 16), [
            'endpointId' => self::ENDPOINT, 'host'=>'pve-new.example.test','port'=>8006,'priority'=>100,'tlsMode'=>'system_ca','customCaPem'=>null,'sha256Fingerprint'=>null,
        ]), new ConfigurationCommand(ConfigurationCommandType::CredentialRotate, self::CONNECTION, 1, 'backup-token', str_repeat('b',16), [
            'purpose'=>'backup','principal'=>'executor@pve','tokenName'=>'backup',
        ], PlaintextSecret::fromString('backup-worker-token-secret')), new ConfigurationCommand(
            ConfigurationCommandType::ConnectionEnable,
            self::CONNECTION,
            1,
            'enable',
            str_repeat('n', 16),
        )];
        foreach ($commands as $command) {
            $result = $repository->execute($command, $principal);
            self::assertSame(ConfigurationCommandStatus::Blocked, $result->status);
            self::assertSame(['verified_onboarding_required'], $result->blockers);
        }
        self::assertSame(0, $this->connection()->fetchOne('SELECT enabled FROM proxmox_connections WHERE id=?', [self::CONNECTION]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_connection_endpoints WHERE connection_id=?', [self::CONNECTION]));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM proxmox_credentials WHERE connection_id=?', [self::CONNECTION]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM proxmox_connections WHERE id=?', [self::CONNECTION]));
        self::assertSame(5, $this->connection()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE subject_type='connection' AND subject_id=?", [self::CONNECTION]));
        self::assertSame(5, $this->connection()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE subject_type='connection' AND subject_id=? AND actor_session_id=?", [self::CONNECTION, $sessionId]));
    }

    public function testMigrationCheckContainsLiteralArgon2idPrefix(): void
    {
        $clause = $this->connection()->fetchOne("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='chk_configuration_idempotency_secret'");
        self::assertIsString($clause);
        self::assertStringContainsString('$argon2id$%', $clause);
    }

    public function testActiveConnectionCannotDisableAnUnverifiedNonLastEndpoint(): void
    {
        $this->seedUser();
        $repository = self::getContainer()->get(DbalConnectionAdministration::class);
        self::assertInstanceOf(DbalConnectionAdministration::class, $repository);
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('admin'), [Permission::BackupConfigurationManage]);
        $this->seedConnection();
        $endpointId = 'endpoint-id-0002';
        $this->seedEndpoint($endpointId, 'pve-unverified.example.test', 200);
        $this->connection()->update('proxmox_connections', ['enabled' => 1], ['id' => self::CONNECTION]);

        $result = $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::EndpointDisable,
            self::CONNECTION,
            1,
            'disable-unverified-endpoint',
            str_repeat('f', 16),
            ['endpointId' => $endpointId],
        ), $principal);

        self::assertSame(ConfigurationCommandStatus::Blocked, $result->status);
        self::assertSame(['verified_onboarding_required'], $result->blockers);
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
        self::assertSame(1, $this->connection()->fetchOne('SELECT enabled FROM proxmox_connection_endpoints WHERE id = ?', [$endpointId]));
    }

    public function testActiveEndpointCreateAndUpdateRemainClosedAndTheLastEndpointCannotBeDisabled(): void
    {
        $this->seedUser();
        $repository = self::getContainer()->get(DbalConnectionAdministration::class);
        self::assertInstanceOf(DbalConnectionAdministration::class, $repository);
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('admin'), [Permission::BackupConfigurationManage]);
        $this->seedConnection();
        $this->connection()->update('proxmox_connections', ['enabled' => 1], ['id' => self::CONNECTION]);

        $commands = [
            new ConfigurationCommand(ConfigurationCommandType::EndpointCreate, self::CONNECTION, 1, 'active-endpoint-create', str_repeat('a',16), [
                'host'=>'pve-b.example.test','port'=>8006,'priority'=>200,'tlsMode'=>'system_ca','customCaPem'=>null,'sha256Fingerprint'=>null,
            ]),
            new ConfigurationCommand(ConfigurationCommandType::EndpointUpdate, self::CONNECTION, 1, 'active-endpoint-update', str_repeat('b',16), [
                'endpointId'=>self::ENDPOINT,'host'=>'pve-new.example.test','port'=>8006,'priority'=>100,'tlsMode'=>'system_ca','customCaPem'=>null,'sha256Fingerprint'=>null,
            ]),
        ];
        foreach ($commands as $command) {
            $result = $repository->execute($command, $principal);
            self::assertSame(ConfigurationCommandStatus::Blocked, $result->status);
            self::assertSame(['verified_onboarding_required'], $result->blockers);
        }
        $lastEndpoint = $repository->execute(new ConfigurationCommand(
            ConfigurationCommandType::EndpointDisable,
            self::CONNECTION,
            1,
            'active-endpoint-disable',
            str_repeat('d', 16),
            ['endpointId' => self::ENDPOINT],
        ), $principal);
        self::assertSame(ConfigurationCommandStatus::Blocked, $lastEndpoint->status);
        self::assertSame(['last_enabled_endpoint'], $lastEndpoint->blockers);
        self::assertSame(1, $this->connection()->fetchOne('SELECT revision FROM proxmox_connections WHERE id=?', [self::CONNECTION]));
        self::assertSame('pve.example.test', $this->connection()->fetchOne('SELECT host FROM proxmox_connection_endpoints WHERE id=?', [self::ENDPOINT]));

        $displayName = new ConfigurationCommand(
            ConfigurationCommandType::ConnectionUpdate,
            self::CONNECTION,
            1,
            'active-display-name-update',
            str_repeat('u', 16),
            ['displayName' => 'PVE renamed'],
        );
        self::assertSame(2, $repository->execute($displayName, $principal)->revision);
        self::assertSame('PVE renamed', $this->connection()->fetchOne('SELECT display_name FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));

        $disable = new ConfigurationCommand(ConfigurationCommandType::ConnectionDisable, self::CONNECTION, 2, 'disable-connection', str_repeat('x',16));
        self::assertSame(3, $repository->execute($disable, $principal)->revision);
        self::assertSame(0, $this->connection()->fetchOne('SELECT enabled FROM proxmox_connections WHERE id = ?', [self::CONNECTION]));
    }

    private function seedConnection(): void
    {
        $now = '2026-07-13 00:00:00.000000';
        $this->connection()->insert('proxmox_connections', [
            'id' => self::CONNECTION,
            'display_name' => 'PVE',
            'product' => 'pve',
            'enabled' => 0,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->seedEndpoint(self::ENDPOINT, 'pve.example.test', 100);
    }

    private function seedEndpoint(string $id, string $host, int $priority): void
    {
        $now = '2026-07-13 00:00:00.000000';
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => $id,
            'connection_id' => self::CONNECTION,
            'host' => $host,
            'port' => 8006,
            'priority' => $priority,
            'enabled' => 1,
            'tls_mode' => 'system_ca',
            'custom_ca_pem' => null,
            'sha256_fingerprint' => null,
            'last_attempted_at' => null,
            'last_success_at' => null,
            'last_error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedUser(): void
    {
        $now='2026-07-13 00:00:00.000000';
        $this->connection()->insert('users',['id'=>self::USER,'username'=>'admin','display_name'=>'Admin','password_hash'=>'$argon2id$dummy','enabled'=>1,'revision'=>1,'created_at'=>$now,'updated_at'=>$now]);
    }

    private function uuid(string $binary): string
    {
        $hex=bin2hex($binary);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
