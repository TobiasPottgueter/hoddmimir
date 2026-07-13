<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveCertificateFingerprint;
use App\Infrastructure\Proxmox\PveCustomCaCertificate;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use App\Infrastructure\Proxmox\PveBackup\PveBackupClientFactory;
use App\Infrastructure\Proxmox\PveBackup\PveBackupEndpointConfiguration;
use Doctrine\DBAL\Connection;

final readonly class DbalPveBackupClientProvider implements PveBackupClientProvider
{
    public function __construct(
        private Connection $connection,
        private PveBackupClientFactory $factory,
    ) {
    }

    public function forRequest(string $requestId): PveBackupClient
    {
        if (16 !== strlen($requestId)) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::Configuration);
        }
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT endpoint.host, endpoint.port, endpoint.tls_mode, endpoint.custom_ca_pem,
       endpoint.sha256_fingerprint, credential.id AS credential_id,
       credential.principal, credential.token_name, credential.secret_envelope,
       capability.version_major, capability.version_minor, capability.version_patch,
       capability.release_name, capability.raw_version
FROM backup_requests request
JOIN proxmox_connections connection
  ON connection.id = request.connection_id AND connection.enabled = 1 AND connection.product = 'pve'
JOIN proxmox_connection_endpoints endpoint
  ON endpoint.connection_id = connection.id AND endpoint.enabled = 1
JOIN backup_credentials credential ON credential.connection_id = connection.id
JOIN proxmox_capability_snapshots capability
  ON capability.id = (
      SELECT latest.id FROM proxmox_capability_snapshots latest
      WHERE latest.connection_id = connection.id AND latest.product = 'pve'
      ORDER BY latest.last_observed_at DESC, latest.id DESC LIMIT 1
  )
WHERE request.id = :request
ORDER BY endpoint.priority, endpoint.id
LIMIT 1
SQL, ['request' => $requestId]);
        if (false === $row) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::Configuration);
        }

        try {
            $credentialId = $this->binary($row, 'credential_id', 16);
            $configuration = new PveBackupEndpointConfiguration(
                $this->text($row, 'host'),
                $this->integer($row, 'port'),
                $this->tls($row),
                PveApiTokenIdentity::fromUserAndTokenId(
                    $this->text($row, 'principal'),
                    $this->text($row, 'token_name'),
                ),
                EncryptedSecret::fromEncoded($this->text($row, 'secret_envelope')),
                SecretContext::forBinaryCredentialId($credentialId, SecretPurpose::PveBackupToken),
            );
            $version = new PveVersion(
                $this->integer($row, 'version_major'),
                $this->integer($row, 'version_minor'),
                null === ($row['version_patch'] ?? null) ? null : $this->integer($row, 'version_patch'),
                $this->nullableText($row, 'release_name') ?? 'unknown',
                $this->text($row, 'raw_version'),
                'unknown',
            );

            return $this->factory->create($configuration, $version);
        } catch (\Throwable) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::Configuration);
        }
    }

    /** @param array<string, mixed> $row */
    private function tls(array $row): PveTlsConfiguration
    {
        return match ($row['tls_mode'] ?? null) {
            'system_ca' => PveTlsConfiguration::systemCa(),
            'custom_ca' => PveTlsConfiguration::customCa(
                PveCustomCaCertificate::fromPem($this->text($row, 'custom_ca_pem')),
            ),
            'sha256_fingerprint' => PveTlsConfiguration::certificateFingerprint(
                PveCertificateFingerprint::fromSha256(bin2hex($this->binary($row, 'sha256_fingerprint', 32))),
            ),
            default => throw new \RuntimeException('Invalid backup TLS configuration.'),
        };
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && strlen($value) < 19) {
            return (int) $value;
        }
        throw new \RuntimeException('Invalid backup integer configuration.');
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key, int $length): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $length !== strlen($value)) {
            throw new \RuntimeException('Invalid backup binary configuration.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException('Invalid backup text configuration.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableText(array $row, string $key): ?string
    {
        return null === ($row[$key] ?? null) ? null : $this->text($row, $key);
    }
}
