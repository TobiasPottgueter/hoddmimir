<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshClaim;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshEndpoint;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceConfigurationSource;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceEndpointConfiguration;
use App\Infrastructure\Proxmox\PveCertificateFingerprint;
use App\Infrastructure\Proxmox\PveCustomCaCertificate;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Throwable;

final readonly class DbalPveExecutorEvidenceConfigurationSource implements PveExecutorEvidenceConfigurationSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function load(
        ExecutorEvidenceRefreshClaim $claim,
        ExecutorEvidenceRefreshEndpoint $endpoint,
    ): PveExecutorEvidenceEndpointConfiguration {
        try {
            $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT catalog.connection_id, catalog.connection_revision, catalog.endpoint_id,
       catalog.priority, catalog.host, catalog.port, catalog.tls_mode,
       catalog.custom_ca_pem, catalog.sha256_fingerprint,
       catalog.backup_credential_id, catalog.backup_credential_revision,
       catalog.scan_credential_id, catalog.scan_credential_revision,
       catalog.version_major,
       backup.principal AS backup_principal, backup.token_name AS backup_token_name,
       backup.secret_envelope AS backup_secret_envelope,
       scan.principal AS scan_principal, scan.token_name AS scan_token_name,
       scan.secret_envelope AS scan_secret_envelope
FROM executor_evidence_endpoint_catalog catalog
INNER JOIN backup_credentials backup
        ON backup.connection_id = catalog.connection_id
       AND backup.id = catalog.backup_credential_id
       AND backup.revision = catalog.backup_credential_revision
INNER JOIN executor_scan_credentials scan
        ON scan.connection_id = catalog.connection_id
       AND scan.id = catalog.scan_credential_id
       AND scan.revision = catalog.scan_credential_revision
WHERE catalog.connection_id = :connection AND catalog.endpoint_id = :endpoint
  AND catalog.connection_revision = :connection_revision
  AND catalog.backup_credential_revision = :backup_revision
  AND catalog.scan_credential_revision = :scan_revision
LIMIT 1
SQL, [
                'connection' => $claim->connectionId,
                'endpoint' => $endpoint->id,
                'connection_revision' => $claim->connectionRevision,
                'backup_revision' => $claim->backupCredentialRevision,
                'scan_revision' => $claim->scanCredentialRevision,
            ], [
                'connection' => ParameterType::BINARY,
                'endpoint' => ParameterType::BINARY,
                'connection_revision' => ParameterType::INTEGER,
                'backup_revision' => ParameterType::INTEGER,
                'scan_revision' => ParameterType::INTEGER,
            ]);
        } catch (Throwable) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::ConfigurationChanged);
        }
        if (false === $row) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::ConfigurationChanged);
        }

        try {
            $backupCredentialId = $this->binary($row, 'backup_credential_id', 16);
            $scanCredentialId = $this->binary($row, 'scan_credential_id', 16);
            $backupIdentity = $this->text($row, 'backup_principal').'!'.$this->text($row, 'backup_token_name');
            $scanIdentity = $this->text($row, 'scan_principal').'!'.$this->text($row, 'scan_token_name');
            $backupSecret = EncryptedSecret::fromEncoded($this->text($row, 'backup_secret_envelope'));
            $scanSecret = EncryptedSecret::fromEncoded($this->text($row, 'scan_secret_envelope'));
        } catch (Throwable) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::CredentialUnavailable);
        }

        try {
            $tls = $this->tls($row);
        } catch (Throwable) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::Tls);
        }

        try {
            return new PveExecutorEvidenceEndpointConfiguration(
                $claim->connectionId,
                $endpoint->id,
                $claim->connectionRevision,
                $claim->backupCredentialRevision,
                $claim->scanCredentialRevision,
                $this->integer($row, 'version_major'),
                $this->text($row, 'host'),
                $this->integer($row, 'port'),
                $tls,
                $backupIdentity,
                $backupSecret,
                SecretContext::forBinaryCredentialId($backupCredentialId, SecretPurpose::PveBackupToken),
                $scanIdentity,
                $scanSecret,
                SecretContext::forBinaryCredentialId($scanCredentialId, SecretPurpose::PveCollectorToken),
            );
        } catch (Throwable) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::ConfigurationChanged);
        }
    }

    /** @param array<string, mixed> $row */
    private function tls(array $row): PveTlsConfiguration
    {
        return match ($row['tls_mode'] ?? null) {
            'system_ca' => $this->systemCa($row),
            'custom_ca' => $this->customCa($row),
            'sha256_fingerprint' => $this->fingerprint($row),
            default => throw new \RuntimeException('Invalid executor evidence TLS mode.'),
        };
    }

    /** @param array<string, mixed> $row */
    private function systemCa(array $row): PveTlsConfiguration
    {
        if (null !== ($row['custom_ca_pem'] ?? null) || null !== ($row['sha256_fingerprint'] ?? null)) {
            throw new \RuntimeException('Non-exclusive executor evidence TLS configuration.');
        }
        return PveTlsConfiguration::systemCa();
    }

    /** @param array<string, mixed> $row */
    private function customCa(array $row): PveTlsConfiguration
    {
        if (null !== ($row['sha256_fingerprint'] ?? null)) {
            throw new \RuntimeException('Non-exclusive executor evidence TLS configuration.');
        }
        return PveTlsConfiguration::customCa(PveCustomCaCertificate::fromPem($this->text($row, 'custom_ca_pem')));
    }

    /** @param array<string, mixed> $row */
    private function fingerprint(array $row): PveTlsConfiguration
    {
        if (null !== ($row['custom_ca_pem'] ?? null)) {
            throw new \RuntimeException('Non-exclusive executor evidence TLS configuration.');
        }
        return PveTlsConfiguration::certificateFingerprint(
            PveCertificateFingerprint::fromSha256(bin2hex($this->binary($row, 'sha256_fingerprint', 32))),
        );
    }

    /** @param array<string, mixed> $row */
    private function binary(array $row, string $key, int $length): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $length !== strlen($value)) {
            throw new \RuntimeException('Invalid executor evidence binary configuration.');
        }
        return $value;
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
        throw new \RuntimeException('Invalid executor evidence integer configuration.');
    }

    /** @param array<string, mixed> $row */
    private function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException('Invalid executor evidence text configuration.');
        }
        return $value;
    }
}
