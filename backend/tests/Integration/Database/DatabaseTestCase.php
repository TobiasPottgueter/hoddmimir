<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->connection->close();
        }

        parent::tearDown();
    }

    final protected function connection(): Connection
    {
        return $this->connection;
    }

    final protected function databaseName(): string
    {
        $databaseName = $this->connection->fetchOne('SELECT DATABASE()');
        self::assertIsString($databaseName);
        self::assertNotSame('', $databaseName);

        return $databaseName;
    }

    /** Seed the explicit endpoint and two purpose-scoped credential prerequisites used by evidence fixtures. */
    final protected function seedExecutorEvidenceFixtureConfiguration(string $connectionId): void
    {
        self::assertSame(16, strlen($connectionId));
        $endpoint = $this->connection->fetchOne(
            'SELECT id FROM proxmox_connection_endpoints WHERE connection_id = :connection LIMIT 1',
            ['connection' => $connectionId],
            ['connection' => ParameterType::BINARY],
        );
        if (false === $endpoint) {
            $this->connection->insert('proxmox_connection_endpoints', [
                'id' => random_bytes(16),
                'connection_id' => $connectionId,
                'host' => 'fixture-'.bin2hex(substr($connectionId, 0, 4)).'.test',
                'port' => 8006,
                'priority' => 100,
                'enabled' => 1,
                'tls_mode' => 'system_ca',
                'created_at' => '2026-07-12 10:00:00.000000',
                'updated_at' => '2026-07-12 10:00:00.000000',
            ], ['connection_id' => ParameterType::BINARY]);
        }
        foreach (['backup', 'collector'] as $purpose) {
            $existing = $this->connection->fetchOne(<<<'SQL'
SELECT id FROM proxmox_credentials
WHERE connection_id = :connection AND purpose = :purpose
LIMIT 1
SQL, ['connection' => $connectionId, 'purpose' => $purpose], ['connection' => ParameterType::BINARY]);
            if (false !== $existing) {
                continue;
            }
            $this->connection->insert('proxmox_credentials', [
                'id' => random_bytes(16),
                'connection_id' => $connectionId,
                'purpose' => $purpose,
                'auth_scheme' => 'api_token',
                'principal' => $purpose.'@pve',
                'token_name' => 'fixture',
                'secret_envelope' => 'opaque-'.$purpose,
                'envelope_version' => 1,
                'key_id' => 'fixture',
                'revision' => 1,
                'created_at' => '2026-07-12 10:00:00.000000',
                'updated_at' => '2026-07-12 10:00:00.000000',
            ], ['connection_id' => ParameterType::BINARY]);
        }
    }

    /**
     * Test fixtures that bypass the runtime producer must still model its
     * atomic current-set publication contract. Production code never calls
     * this helper. Missing prerequisites are assertion failures rather than
     * being silently synthesized here.
     */
    final protected function publishExecutorEvidenceFixture(string $connectionId, int $setRevision = 1): void
    {
        self::assertSame(16, strlen($connectionId));
        $connectionRevision = $this->connection->fetchOne(
            "SELECT revision FROM proxmox_connections WHERE id = :connection AND product = 'pve' AND enabled = 1",
            ['connection' => $connectionId],
            ['connection' => ParameterType::BINARY],
        );
        self::assertTrue(is_int($connectionRevision) || is_string($connectionRevision));

        $endpointId = $this->connection->fetchOne(<<<'SQL'
SELECT id FROM proxmox_connection_endpoints
WHERE connection_id = :connection AND enabled = 1
ORDER BY priority, id LIMIT 1
SQL, ['connection' => $connectionId], ['connection' => ParameterType::BINARY]);
        self::assertIsString($endpointId);

        $revisions = [];
        foreach (['backup', 'collector'] as $purpose) {
            $credential = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, revision FROM proxmox_credentials
WHERE connection_id = :connection AND purpose = :purpose
LIMIT 1
SQL, ['connection' => $connectionId, 'purpose' => $purpose], ['connection' => ParameterType::BINARY]);
            self::assertIsArray($credential, 'Executor evidence fixtures require explicit '.$purpose.' credentials.');
            $revision = $credential['revision'] ?? null;
            self::assertTrue(is_int($revision) || is_string($revision));
            $revisions[$purpose] = (int) $revision;
        }

        $this->connection->update('executor_permission_evidence', [
            'evidence_set_revision' => $setRevision,
            'endpoint_id' => $endpointId,
            'connection_revision' => (int) $connectionRevision,
            'backup_credential_revision' => $revisions['backup'],
            'scan_credential_revision' => $revisions['collector'],
            'revision' => $setRevision,
        ], ['connection_id' => $connectionId], [
            'endpoint_id' => ParameterType::BINARY,
            'connection_id' => ParameterType::BINARY,
        ]);
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO executor_evidence_refresh_state
    (connection_id, next_due_at, lease_fence, published_set_revision,
     published_endpoint_id, published_connection_revision,
     published_backup_credential_revision, published_scan_credential_revision,
     last_success_at, updated_at)
VALUES
    (:connection, UTC_TIMESTAMP(6) + INTERVAL 120 SECOND, :set_revision, :set_revision,
     :endpoint, :connection_revision, :backup_revision, :scan_revision,
     UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE
    published_set_revision = VALUES(published_set_revision),
    published_endpoint_id = VALUES(published_endpoint_id),
    published_connection_revision = VALUES(published_connection_revision),
    published_backup_credential_revision = VALUES(published_backup_credential_revision),
    published_scan_credential_revision = VALUES(published_scan_credential_revision),
    last_success_at = VALUES(last_success_at), updated_at = VALUES(updated_at)
SQL, [
            'connection' => $connectionId,
            'set_revision' => $setRevision,
            'endpoint' => $endpointId,
            'connection_revision' => (int) $connectionRevision,
            'backup_revision' => $revisions['backup'],
            'scan_revision' => $revisions['collector'],
        ], [
            'connection' => ParameterType::BINARY,
            'endpoint' => ParameterType::BINARY,
            'set_revision' => ParameterType::INTEGER,
            'connection_revision' => ParameterType::INTEGER,
            'backup_revision' => ParameterType::INTEGER,
            'scan_revision' => ParameterType::INTEGER,
        ]);
    }

    final protected function seedWebSession(
        string $userId,
        string $sessionId = 'session-audit-id',
    ): string {
        self::assertSame(16, strlen($userId));
        self::assertSame(16, strlen($sessionId));

        $this->connection->insert('web_sessions', [
            'id' => $sessionId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', "session-token\0".$sessionId, true),
            'csrf_secret_hash' => hash('sha256', "csrf-token\0".$sessionId, true),
            'issued_at' => '2026-07-12 10:00:00.000000',
            'last_seen_at' => '2026-07-12 10:01:00.000000',
            'idle_expires_at' => '2026-07-12 10:31:00.000000',
            'absolute_expires_at' => '2026-07-12 22:00:00.000000',
            'revoked_at' => null,
        ], [
            'id' => ParameterType::BINARY,
            'user_id' => ParameterType::BINARY,
            'token_hash' => ParameterType::BINARY,
            'csrf_secret_hash' => ParameterType::BINARY,
        ]);

        return $sessionId;
    }
}
