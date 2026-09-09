<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Readiness\ReadinessAggregator;
use App\Infrastructure\Persistence\MariaDb\DbalReferencedCredentialKeyIds;
use App\Infrastructure\Readiness\EncryptionKeyRingReadinessCheck;
use App\Infrastructure\Security\DockerSecretKeyRingLoader;

final class EncryptionKeyRingReadinessCheckTest extends DatabaseTestCase
{
    private string $keyRingFile;

    protected function setUp(): void
    {
        parent::setUp();

        $keyRingFile = tempnam(sys_get_temp_dir(), 'hoddmimir-integration-keyring-');
        self::assertIsString($keyRingFile);
        $this->keyRingFile = $keyRingFile;
        file_put_contents($this->keyRingFile, json_encode([
            'format' => 1,
            'revision' => 1,
            'primaryKeyId' => 'key_current',
            'keys' => [
                ['id' => 'key_old', 'material' => str_repeat('a', 64)],
                ['id' => 'key_current', 'material' => str_repeat('b', 64)],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (isset($this->keyRingFile)) {
            unlink($this->keyRingFile);
        }

        parent::tearDown();
    }

    public function testValidKeyringWithoutCredentialsIsReady(): void
    {
        self::assertSame(['status' => 'ready'], $this->check()->check()->toArray());
    }

    public function testReferencedAvailableKeyIsReady(): void
    {
        $this->insertCredential('key_old');

        self::assertSame(['status' => 'ready'], $this->check()->check()->toArray());
    }

    public function testReferencedMissingKeyIsUnavailable(): void
    {
        $this->insertCredential('retired_key_SENTINEL');

        $result = $this->check()->check()->toArray();
        self::assertSame(['status' => 'unavailable', 'reason' => 'missing_referenced_key'], $result);
        self::assertStringNotContainsString('SENTINEL', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testConfiguredRuntimeAggregatorFailsForAReferencedMissingKey(): void
    {
        $this->insertCredential('runtime_missing_key_SENTINEL');
        $aggregator = self::getContainer()->get(ReadinessAggregator::class);
        self::assertInstanceOf(ReadinessAggregator::class, $aggregator);

        $checks = $aggregator->assess()->checks();

        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'missing_referenced_key'],
            $checks['encryption_keyring'] ?? null,
        );
        self::assertStringNotContainsString('SENTINEL', json_encode($checks, JSON_THROW_ON_ERROR));
    }

    private function check(): EncryptionKeyRingReadinessCheck
    {
        return new EncryptionKeyRingReadinessCheck(
            new DockerSecretKeyRingLoader($this->keyRingFile, '1'),
            new DbalReferencedCredentialKeyIds($this->connection()),
        );
    }

    private function insertCredential(string $keyId): void
    {
        $connectionId = random_bytes(16);
        $now = '2026-07-10 12:00:00.000000';
        $this->connection()->insert('proxmox_connections', [
            'id' => $connectionId,
            'display_name' => 'readiness-'.bin2hex(random_bytes(6)),
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->insert('proxmox_credentials', [
            'id' => random_bytes(16),
            'connection_id' => $connectionId,
            'purpose' => 'collector',
            'auth_scheme' => 'api_token',
            'principal' => 'readiness-test@pve',
            'token_name' => 'collector',
            'secret_envelope' => 'encrypted-test-value',
            'envelope_version' => 1,
            'key_id' => $keyId,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
