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
use App\Infrastructure\Proxmox\Pbs\PbsApiTokenIdentity;
use App\Infrastructure\Proxmox\Pbs\PbsCertificateFingerprint;
use App\Infrastructure\Proxmox\Pbs\PbsCustomCaCertificate;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;

final readonly class DbalPbsEndpointReadConfigurationSource implements PbsEndpointReadConfigurationSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function load(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
    ): PbsEndpointReadConfiguration {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('The expected connection revision is invalid.');
        }

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
            || 'pbs' !== ($row['product'] ?? null)
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

        try {
            [$username, $realm] = $this->principal($this->string($row, 'principal'));
            $identity = PbsApiTokenIdentity::fromParts($username, $realm, $this->string($row, 'token_name'));
            $encryptedSecret = EncryptedSecret::fromEncoded($this->string($row, 'secret_envelope'));
            $secretContext = SecretContext::forBinaryCredentialId(
                $this->binary($row, 'credential_id'),
                SecretPurpose::PbsCollectorToken,
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
            return new PbsEndpointReadConfiguration(
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

    /** @return array{string, string} */
    private function principal(string $principal): array
    {
        $separator = strrpos($principal, '@');
        if (false === $separator || 0 === $separator || $separator === strlen($principal) - 1) {
            throw new RuntimeException('MariaDB returned an invalid PBS token principal.');
        }

        return [substr($principal, 0, $separator), substr($principal, $separator + 1)];
    }

    /** @param array<string, mixed> $row */
    private function tls(array $row): PbsTlsConfiguration
    {
        return match ($row['tls_mode'] ?? null) {
            'system_ca' => $this->systemCa($row),
            'custom_ca' => $this->customCa($row),
            'sha256_fingerprint' => $this->fingerprint($row),
            default => throw new RuntimeException('MariaDB returned an unsupported PBS TLS mode.'),
        };
    }

    /** @param array<string, mixed> $row */
    private function systemCa(array $row): PbsTlsConfiguration
    {
        if (null !== ($row['custom_ca_pem'] ?? null) || null !== ($row['sha256_fingerprint'] ?? null)) {
            throw new RuntimeException('MariaDB returned a non-exclusive PBS TLS configuration.');
        }

        return PbsTlsConfiguration::systemCa();
    }

    /** @param array<string, mixed> $row */
    private function customCa(array $row): PbsTlsConfiguration
    {
        if (null !== ($row['sha256_fingerprint'] ?? null)) {
            throw new RuntimeException('MariaDB returned a non-exclusive PBS TLS configuration.');
        }

        return PbsTlsConfiguration::customCa(
            PbsCustomCaCertificate::fromPem($this->string($row, 'custom_ca_pem')),
        );
    }

    /** @param array<string, mixed> $row */
    private function fingerprint(array $row): PbsTlsConfiguration
    {
        if (null !== ($row['custom_ca_pem'] ?? null)) {
            throw new RuntimeException('MariaDB returned a non-exclusive PBS TLS configuration.');
        }

        return PbsTlsConfiguration::certificateFingerprint(
            PbsCertificateFingerprint::fromSha256(bin2hex($this->binary($row, 'sha256_fingerprint', 32))),
        );
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key, int $length = 16): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $length !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid binary PBS configuration value.');
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

        throw new RuntimeException('MariaDB returned an invalid integer PBS configuration value.');
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid string PBS configuration value.');
        }

        return $value;
    }
}
