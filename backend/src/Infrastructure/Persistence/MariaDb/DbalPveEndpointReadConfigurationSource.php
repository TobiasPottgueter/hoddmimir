<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadFailure;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveCertificateFingerprint;
use App\Infrastructure\Proxmox\PveCustomCaCertificate;
use App\Infrastructure\Proxmox\PveEndpointReadConfiguration;
use App\Infrastructure\Proxmox\PveEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;

final readonly class DbalPveEndpointReadConfigurationSource implements PveEndpointReadConfigurationSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function load(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
    ): PveEndpointReadConfiguration {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('The expected connection revision is invalid.');
        }

        // One statement provides a transaction-consistent view of the
        // connection, its owned enabled endpoint and its collector token.
        // DBAL/driver failures intentionally propagate unchanged.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    c.product,
                    c.enabled AS connection_enabled,
                    c.revision AS connection_revision,
                    e.id AS endpoint_id,
                    e.host,
                    e.port,
                    e.tls_mode,
                    e.custom_ca_pem,
                    e.sha256_fingerprint,
                    cr.id AS credential_id,
                    cr.principal,
                    cr.token_name,
                    cr.secret_envelope
                FROM proxmox_connections c
                LEFT JOIN proxmox_connection_endpoints e
                    ON e.connection_id = c.id
                    AND e.id = :endpoint_id
                    AND e.enabled = 1
                LEFT JOIN collector_credentials cr
                    ON cr.connection_id = c.id
                    AND cr.purpose = 'collector'
                    AND cr.auth_scheme = 'api_token'
                WHERE c.id = :connection_id
                LIMIT 1
                SQL,
            [
                'connection_id' => $connectionId->bytes,
                'endpoint_id' => $endpointId->bytes,
            ],
        );

        if (false === $row
            || 'pve' !== ($row['product'] ?? null)
            || 1 !== $this->integer($row, 'connection_enabled')
            || $expectedRevision !== $this->integer($row, 'connection_revision')) {
            throw ConnectionReadFailure::connectionChanged();
        }

        if (null === ($row['endpoint_id'] ?? null)) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::RootUnusable);
        }
        if (null === ($row['credential_id'] ?? null)) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::CredentialUnavailable);
        }

        $credentialId = $this->binary($row, 'credential_id');
        try {
            $identity = PveApiTokenIdentity::fromUserAndTokenId(
                $this->string($row, 'principal'),
                $this->string($row, 'token_name'),
            );
            $encryptedSecret = EncryptedSecret::fromEncoded($this->string($row, 'secret_envelope'));
            $secretContext = SecretContext::forBinaryCredentialId(
                $credentialId,
                SecretPurpose::PveCollectorToken,
            );
        } catch (InvalidArgumentException|RuntimeException) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::CredentialUnavailable);
        }

        try {
            $tls = $this->tls($row);
        } catch (InvalidArgumentException|RuntimeException) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::Tls);
        }

        try {
            return new PveEndpointReadConfiguration(
                $this->string($row, 'host'),
                $this->integer($row, 'port'),
                $tls,
                $identity,
                $encryptedSecret,
                $secretContext,
            );
        } catch (InvalidArgumentException|RuntimeException) {
            throw EndpointReadFailure::for(EndpointReadFailureCode::RootUnusable);
        }
    }

    /** @param array<string, mixed> $row */
    private function tls(array $row): PveTlsConfiguration
    {
        return match ($row['tls_mode'] ?? null) {
            'system_ca' => $this->systemCa($row),
            'custom_ca' => $this->customCa($row),
            'sha256_fingerprint' => $this->fingerprint($row),
            default => throw new RuntimeException('MariaDB returned an unsupported PVE TLS mode.'),
        };
    }

    /** @param array<string, mixed> $row */
    private function systemCa(array $row): PveTlsConfiguration
    {
        if (null !== ($row['custom_ca_pem'] ?? null) || null !== ($row['sha256_fingerprint'] ?? null)) {
            throw new RuntimeException('MariaDB returned a non-exclusive PVE TLS configuration.');
        }

        return PveTlsConfiguration::systemCa();
    }

    /** @param array<string, mixed> $row */
    private function customCa(array $row): PveTlsConfiguration
    {
        if (null !== ($row['sha256_fingerprint'] ?? null)) {
            throw new RuntimeException('MariaDB returned a non-exclusive PVE TLS configuration.');
        }

        return PveTlsConfiguration::customCa(
            PveCustomCaCertificate::fromPem($this->string($row, 'custom_ca_pem')),
        );
    }

    /** @param array<string, mixed> $row */
    private function fingerprint(array $row): PveTlsConfiguration
    {
        if (null !== ($row['custom_ca_pem'] ?? null)) {
            throw new RuntimeException('MariaDB returned a non-exclusive PVE TLS configuration.');
        }
        $fingerprint = $this->binary($row, 'sha256_fingerprint', 32);

        return PveTlsConfiguration::certificateFingerprint(
            PveCertificateFingerprint::fromSha256(bin2hex($fingerprint)),
        );
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key, int $length = 16): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $length !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid binary PVE configuration value.');
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

        throw new RuntimeException('MariaDB returned an invalid integer PVE configuration value.');
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid string PVE configuration value.');
        }

        return $value;
    }
}
