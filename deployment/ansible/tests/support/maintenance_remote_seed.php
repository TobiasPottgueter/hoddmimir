<?php

declare(strict_types=1);

use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Security\EncryptionKeyRing;
use App\Infrastructure\Security\SodiumSecretCipher;
use App\Infrastructure\Security\SystemNonceSource;
use Doctrine\DBAL\DriverManager;

require '/app/vendor/autoload.php';

if ('LOCAL_TLS_FIXTURE_ONLY' !== getenv('HODDMIMIR_MAINTENANCE_FIXTURE')) {
    throw new RuntimeException('This helper requires an explicit local fixture acknowledgement.');
}

$connection = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => getenv('DATABASE_HOST'),
    'port' => (int) getenv('DATABASE_PORT'),
    'dbname' => getenv('DATABASE_NAME'),
    'user' => getenv('DATABASE_USER'),
    'password' => trim(file_get_contents(getenv('DATABASE_PASSWORD_FILE'))),
]);
$connectionId = str_repeat('r', 16);
if ('remove' === ($argv[1] ?? '')) {
    foreach (['proxmox_credentials', 'proxmox_connection_endpoints'] as $table) {
        $connection->delete($table, ['connection_id' => $connectionId]);
    }
    $connection->delete('proxmox_connections', ['id' => $connectionId]);
    exit(0);
}

if (0 !== (int) $connection->fetchOne('SELECT COUNT(*) FROM proxmox_connections')) {
    throw new RuntimeException('Remote fixture seeding requires an empty local connection catalog.');
}
$configuration = json_decode(file_get_contents('/tmp/remote-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
if ('172.31.249.1' !== $configuration['host'] || !is_int($configuration['port'])
    || $configuration['port'] < 1024 || $configuration['port'] > 65535) {
    throw new RuntimeException('The fixture must use the dedicated local test bridge.');
}
$keyring = EncryptionKeyRing::fromJson(file_get_contents(getenv('ENCRYPTION_KEY_FILE')));
$cipher = new SodiumSecretCipher($keyring, new SystemNonceSource());
$credentialId = str_repeat('c', 16);
$envelope = $cipher->encrypt(
    PlaintextSecret::fromString(bin2hex(random_bytes(32))),
    SecretContext::forBinaryCredentialId($credentialId, SecretPurpose::PveCollectorToken),
);
$at = gmdate('Y-m-d H:i:s').'.000000';
$connection->transactional(static function ($database) use ($configuration, $connectionId, $credentialId, $envelope, $keyring, $at): void {
    $database->insert('proxmox_connections', [
        'id' => $connectionId, 'display_name' => 'Local maintenance TLS fixture',
        'product' => 'pve', 'enabled' => 0, 'revision' => 1, 'created_at' => $at, 'updated_at' => $at,
    ]);
    $database->insert('proxmox_connection_endpoints', [
        'id' => str_repeat('e', 16), 'connection_id' => $connectionId,
        'host' => $configuration['host'], 'port' => $configuration['port'],
        'priority' => 100, 'enabled' => 0, 'tls_mode' => 'custom_ca',
        'custom_ca_pem' => $configuration['ca'], 'created_at' => $at, 'updated_at' => $at,
    ]);
    $database->insert('proxmox_credentials', [
        'id' => $credentialId, 'connection_id' => $connectionId, 'purpose' => 'collector',
        'auth_scheme' => 'api_token', 'principal' => 'maintenance@pve', 'token_name' => 'collector',
        'secret_envelope' => $envelope->encoded(), 'envelope_version' => 1,
        'key_id' => $keyring->primaryKeyId(), 'revision' => 1, 'created_at' => $at, 'updated_at' => $at,
    ]);
});
