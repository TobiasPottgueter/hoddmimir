<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingActivationRepository;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueSeverity;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationResult;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationStatus;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerification;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerificationIssue;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\Auth\PasswordHasher;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Domain\Shared\Clock;
use App\Infrastructure\Proxmox\Pbs\PbsCustomCaCertificate;
use App\Infrastructure\Proxmox\PveCustomCaCertificate;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalOnboardingActivationRepository implements OnboardingActivationRepository
{
    private const string DB_DATE = 'Y-m-d H:i:s.u';

    public function __construct(
        private Connection $connection,
        private SecretCipher $cipher,
        private PasswordHasher $replayHasher,
        private Clock $clock,
        private SecurityIdentifierGenerator $ids,
    ) {
    }

    public function activate(
        OnboardingActivationCommand $command,
        OnboardingVerification $verification,
        AuthenticatedPrincipal $principal,
    ): OnboardingMutationResult {
        if (!$verification->passed()) {
            return $this->record(
                $command,
                new OnboardingMutationResult(OnboardingMutationStatus::Rejected, $command->connectionId, null, $verification),
                $principal,
            );
        }
        $replaySecret = $this->replaySecret($command);
        try {
            return $this->connection->transactional(function () use ($command, $verification, $principal, $replaySecret): OnboardingMutationResult {
                $existing = $this->idempotency($command, $principal, $replaySecret, true);
                if (null !== $existing) {
                    return $existing;
                }
                $result = match ($command->mode) {
                    OnboardingMode::Activate => $this->activateNew($command, $verification),
                    OnboardingMode::Rotate => $this->rotate($command, $verification),
                    OnboardingMode::EndpointAdd => $this->addEndpoint($command, $verification),
                    OnboardingMode::EndpointUpdate => $this->updateEndpoint($command, $verification),
                };
                $this->persistIdempotency($command, $principal, $result, $replaySecret);
                $this->audit($command, $principal, $result);

                return $result;
            });
        } catch (UniqueConstraintViolationException|RetryableException) {
            return $this->recoverActivationRace($command, $principal, $replaySecret);
        }
    }

    public function record(
        OnboardingActivationCommand $command,
        OnboardingMutationResult $result,
        AuthenticatedPrincipal $principal,
    ): OnboardingMutationResult {
        $replaySecret = $this->replaySecret($command);
        try {
            return $this->connection->transactional(function () use ($command, $result, $principal, $replaySecret): OnboardingMutationResult {
                $existing = $this->idempotency($command, $principal, $replaySecret, true);
                if (null !== $existing) {
                    return $existing;
                }
                $this->persistIdempotency($command, $principal, $result, $replaySecret);
                $this->audit($command, $principal, $result);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->idempotency($command, $principal, $replaySecret, false)
                ?? throw new RuntimeException('The onboarding idempotency race could not be resolved.');
        }
    }

    private function recoverActivationRace(
        OnboardingActivationCommand $command,
        AuthenticatedPrincipal $principal,
        PlaintextSecret $replaySecret,
    ): OnboardingMutationResult {
        // A different idempotency key may concurrently win either the unique
        // connection/display-name insert or the preceding InnoDB gap-lock
        // race. Resolve both to a durable conflict instead of surfacing an
        // infrastructure error. Locking the winning aggregate first is
        // important: it waits for that transaction's idempotency/audit writes
        // to commit before this transaction touches the adjacent command-key
        // range, avoiding a second gap-lock deadlock.
        return $this->connection->transactional(function () use ($command, $principal, $replaySecret): OnboardingMutationResult {
            $this->connection->fetchOne(
                'SELECT id FROM proxmox_connections WHERE id = ? OR display_name = ? FOR UPDATE',
                [$command->connectionId, $command->displayName],
                [ParameterType::BINARY, ParameterType::STRING],
            );
            $existing = $this->idempotency($command, $principal, $replaySecret, true);
            if (null !== $existing) {
                return $existing;
            }
            $result = new OnboardingMutationResult(
                OnboardingMutationStatus::Conflict,
                $command->connectionId,
                $this->currentRevision($command->connectionId),
            );
            $this->persistIdempotency($command, $principal, $result, $replaySecret);
            $this->audit($command, $principal, $result);

            return $result;
        });
    }

    private function activateNew(OnboardingActivationCommand $command, OnboardingVerification $verification): OnboardingMutationResult
    {
        $existing = $this->connection->fetchAssociative(
            'SELECT revision FROM proxmox_connections WHERE id = ? FOR UPDATE',
            [$command->connectionId],
            [ParameterType::BINARY],
        );
        if (false !== $existing || 0 !== $command->expectedRevision) {
            return new OnboardingMutationResult(
                OnboardingMutationStatus::Conflict,
                $command->connectionId,
                false === $existing ? 0 : $this->integer($existing['revision']),
            );
        }
        $displayNameOwner = $this->connection->fetchOne(
            'SELECT id FROM proxmox_connections WHERE display_name = ? FOR UPDATE',
            [$command->displayName],
            [ParameterType::STRING],
        );
        if (false !== $displayNameOwner) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, 0);
        }
        $now = $this->now();
        $this->connection->insert('proxmox_connections', [
            'id' => $command->connectionId,
            'display_name' => $command->displayName,
            'product' => $command->product->value,
            'enabled' => 1,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['id' => ParameterType::BINARY]);
        $endpointId = $this->insertEndpoint($command, $now);
        $this->writeEndpointEvidence($command, $verification, $endpointId, $now);
        foreach ($command->credentials as $credential) {
            $this->insertCredential($command, $credential, $this->ids->generate(), $now);
        }
        $this->writeOnboardingState($command, $verification, $now);

        return new OnboardingMutationResult(OnboardingMutationStatus::Applied, $command->connectionId, 1, $verification);
    }

    private function rotate(OnboardingActivationCommand $command, OnboardingVerification $verification): OnboardingMutationResult
    {
        $connection = $this->connection->fetchAssociative(
            'SELECT display_name, product, revision FROM proxmox_connections WHERE id = ? FOR UPDATE',
            [$command->connectionId],
            [ParameterType::BINARY],
        );
        if (false === $connection) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, 0);
        }
        $revision = $this->integer($connection['revision']);
        if ($revision !== $command->expectedRevision
            || $connection['product'] !== $command->product->value
            || $connection['display_name'] !== $command->displayName
            || !$this->endpointMatches($command)) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, $revision);
        }
        if (!$this->allEnabledEndpointsVerified($command)) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, $revision);
        }
        $purposes = OnboardingProduct::Pve === $command->product ? ['backup', 'collector'] : ['collector'];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, purpose, revision FROM proxmox_credentials WHERE connection_id = ? AND purpose IN (?) FOR UPDATE',
            [$command->connectionId, $purposes],
            [ParameterType::BINARY, ArrayParameterType::STRING],
        );
        if (count($rows) !== count($purposes)) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, $revision);
        }
        $byPurpose = [];
        foreach ($rows as $row) {
            $byPurpose[$this->text($row['purpose'])] = $row;
        }
        $now = $this->now();
        foreach ($command->credentials as $credential) {
            $purpose = $this->purpose($credential);
            $row = $byPurpose[$purpose] ?? null;
            if (!is_array($row)) {
                throw new RuntimeException('A locked onboarding credential disappeared.');
            }
            $id = $this->binary($row['id']);
            [$principal, $tokenName] = $this->tokenParts($credential->tokenId);
            $encrypted = $this->encrypt($command, $credential, $id);
            $this->connection->update('proxmox_credentials', [
                'principal' => $principal,
                'token_name' => $tokenName,
                'secret_envelope' => $encrypted,
                'secret_verification_hash' => $this->replayHasher->hash($credential->secret)->encoded(),
                'envelope_version' => 1,
                'key_id' => $this->cipher->primaryKeyId(),
                'revision' => $this->integer($row['revision']) + 1,
                'rotated_at' => $now,
                'updated_at' => $now,
            ], ['id' => $id], ['id' => ParameterType::BINARY, 'secret_envelope' => ParameterType::BINARY]);
        }
        $next = $revision + 1;
        $this->connection->update('proxmox_connections', [
            'enabled' => 1,
            'revision' => $next,
            'updated_at' => $now,
        ], ['id' => $command->connectionId], ['id' => ParameterType::BINARY]);
        /** @var string $selectedEndpointId Validated by OnboardingActivationCommand. */
        $selectedEndpointId = $command->endpointId;
        $this->writeEndpointEvidence($command, $verification, $selectedEndpointId, $now);
        $this->writeOnboardingState($command, $verification, $now);

        return new OnboardingMutationResult(OnboardingMutationStatus::Applied, $command->connectionId, $next, $verification);
    }

    private function addEndpoint(OnboardingActivationCommand $command, OnboardingVerification $verification): OnboardingMutationResult
    {
        $connection = $this->lockMatchingConnection($command);
        if ($connection instanceof OnboardingMutationResult) {
            return $connection;
        }
        $revision = $this->integer($connection['revision']);
        if (!$this->credentialsMatch($command)) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, $revision);
        }
        $now = $this->now();
        $endpointId = $this->insertEndpoint($command, $now);
        $this->writeEndpointEvidence($command, $verification, $endpointId, $now);
        $next = $revision + 1;
        $this->connection->update('proxmox_connections', [
            'revision' => $next,
            'updated_at' => $now,
        ], ['id' => $command->connectionId], ['id' => ParameterType::BINARY]);
        $this->writeOnboardingState($command, $verification, $now);

        return new OnboardingMutationResult(OnboardingMutationStatus::Applied, $command->connectionId, $next, $verification);
    }

    private function updateEndpoint(OnboardingActivationCommand $command, OnboardingVerification $verification): OnboardingMutationResult
    {
        $connection = $this->lockMatchingConnection($command);
        if ($connection instanceof OnboardingMutationResult) {
            return $connection;
        }
        $revision = $this->integer($connection['revision']);
        if (!$this->credentialsMatch($command) || null === $command->endpointId) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, $revision);
        }
        $owned = $this->connection->fetchOne(
            'SELECT 1 FROM proxmox_connection_endpoints WHERE connection_id = ? AND id = ? FOR UPDATE',
            [$command->connectionId, $command->endpointId],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
        if (false === $owned) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, $revision);
        }
        [$ca, $fingerprint] = $this->trustMaterial($command);
        $now = $this->now();
        $this->connection->update('proxmox_connection_endpoints', [
            'host' => strtolower($command->endpoint->host),
            'port' => $command->endpoint->port,
            'enabled' => 1,
            'tls_mode' => $command->endpoint->tlsMode->value,
            'custom_ca_pem' => $ca,
            'sha256_fingerprint' => $fingerprint,
            'last_attempted_at' => null,
            'last_success_at' => null,
            'last_error_code' => null,
            'updated_at' => $now,
        ], [
            'connection_id' => $command->connectionId,
            'id' => $command->endpointId,
        ], [
            'connection_id' => ParameterType::BINARY,
            'id' => ParameterType::BINARY,
            'sha256_fingerprint' => ParameterType::BINARY,
        ]);
        $this->writeEndpointEvidence($command, $verification, $command->endpointId, $now);
        $next = $revision + 1;
        $this->connection->update('proxmox_connections', [
            'revision' => $next,
            'updated_at' => $now,
        ], ['id' => $command->connectionId], ['id' => ParameterType::BINARY]);
        $this->writeOnboardingState($command, $verification, $now);

        return new OnboardingMutationResult(OnboardingMutationStatus::Applied, $command->connectionId, $next, $verification);
    }

    /** @return array<string, mixed>|OnboardingMutationResult */
    private function lockMatchingConnection(OnboardingActivationCommand $command): array|OnboardingMutationResult
    {
        $connection = $this->connection->fetchAssociative(
            'SELECT display_name, product, revision FROM proxmox_connections WHERE id = ? FOR UPDATE',
            [$command->connectionId],
            [ParameterType::BINARY],
        );
        if (false === $connection) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, 0);
        }
        $revision = $this->integer($connection['revision']);
        if ($revision !== $command->expectedRevision
            || $connection['product'] !== $command->product->value
            || $connection['display_name'] !== $command->displayName) {
            return new OnboardingMutationResult(OnboardingMutationStatus::Conflict, $command->connectionId, $revision);
        }

        return $connection;
    }

    private function credentialsMatch(OnboardingActivationCommand $command): bool
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, purpose, principal, token_name, secret_verification_hash FROM proxmox_credentials WHERE connection_id = ? FOR UPDATE',
            [$command->connectionId],
            [ParameterType::BINARY],
        );
        if (count($rows) !== count($command->credentials)) {
            return false;
        }
        $byPurpose = [];
        foreach ($rows as $row) {
            $byPurpose[$this->text($row['purpose'])] = $row;
        }
        foreach ($command->credentials as $credential) {
            $purpose = $this->purpose($credential);
            $row = $byPurpose[$purpose] ?? null;
            if (!is_array($row)) {
                return false;
            }
            [$principal, $tokenName] = $this->tokenParts($credential->tokenId);
            if ($this->text($row['principal']) !== $principal || $this->text($row['token_name']) !== $tokenName) {
                return false;
            }
            if (!is_string($row['secret_verification_hash']) || !$this->replayHasher->verify(
                $credential->secret,
                new PasswordHash($row['secret_verification_hash']),
            )) {
                return false;
            }
        }

        return true;
    }

    private function allEnabledEndpointsVerified(OnboardingActivationCommand $command): bool
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT endpoint.id, endpoint.host, endpoint.port, endpoint.tls_mode,
       endpoint.custom_ca_pem, endpoint.sha256_fingerprint,
       evidence.endpoint_config_hash, evidence.detected_product
FROM proxmox_connection_endpoints endpoint
LEFT JOIN proxmox_endpoint_onboarding_evidence evidence
  ON evidence.connection_id = endpoint.connection_id AND evidence.endpoint_id = endpoint.id
WHERE endpoint.connection_id = ? AND endpoint.enabled = 1
FOR UPDATE
SQL,
            [$command->connectionId],
            [ParameterType::BINARY],
        );
        if ([] === $rows) {
            return false;
        }
        foreach ($rows as $row) {
            $id = $this->binary($row['id']);
            if (null !== $command->endpointId && hash_equals($command->endpointId, $id)) {
                continue;
            }
            if ($row['detected_product'] !== $command->product->value
                || !is_string($row['endpoint_config_hash'])
                || !hash_equals($row['endpoint_config_hash'], $this->endpointConfigHash(
                    $command->product,
                    $this->text($row['host']),
                    $this->integer($row['port']),
                    $this->text($row['tls_mode']),
                    null === $row['custom_ca_pem'] ? null : $this->text($row['custom_ca_pem']),
                    null === $row['sha256_fingerprint'] ? null : $this->binary($row['sha256_fingerprint']),
                ))) {
                return false;
            }
        }

        return true;
    }

    private function insertEndpoint(OnboardingActivationCommand $command, string $now): string
    {
        [$ca, $fingerprint] = $this->trustMaterial($command);
        $id = $this->ids->generate();
        $this->connection->insert('proxmox_connection_endpoints', [
            'id' => $id,
            'connection_id' => $command->connectionId,
            'host' => strtolower($command->endpoint->host),
            'port' => $command->endpoint->port,
            'priority' => 100,
            'enabled' => 1,
            'tls_mode' => $command->endpoint->tlsMode->value,
            'custom_ca_pem' => $ca,
            'sha256_fingerprint' => $fingerprint,
            'last_attempted_at' => null,
            'last_success_at' => null,
            'last_error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['id' => ParameterType::BINARY, 'connection_id' => ParameterType::BINARY, 'sha256_fingerprint' => ParameterType::BINARY]);

        return $id;
    }

    private function insertCredential(OnboardingActivationCommand $command, OnboardingCredential $credential, string $id, string $now): void
    {
        [$principal, $tokenName] = $this->tokenParts($credential->tokenId);
        $this->connection->insert('proxmox_credentials', [
            'id' => $id,
            'connection_id' => $command->connectionId,
            'purpose' => $this->purpose($credential),
            'auth_scheme' => 'api_token',
            'principal' => $principal,
            'token_name' => $tokenName,
            'secret_envelope' => $this->encrypt($command, $credential, $id),
            'secret_verification_hash' => $this->replayHasher->hash($credential->secret)->encoded(),
            'envelope_version' => 1,
            'key_id' => $this->cipher->primaryKeyId(),
            'revision' => 1,
            'created_at' => $now,
            'rotated_at' => $now,
            'updated_at' => $now,
        ], ['id' => ParameterType::BINARY, 'connection_id' => ParameterType::BINARY, 'secret_envelope' => ParameterType::BINARY]);
    }

    private function encrypt(OnboardingActivationCommand $command, OnboardingCredential $credential, string $id): string
    {
        return $this->cipher->encrypt(
            $credential->secret,
            SecretContext::forBinaryCredentialId($id, $this->secretPurpose($command->product, $credential->kind)),
        )->encoded();
    }

    private function secretPurpose(OnboardingProduct $product, OnboardingCredentialKind $kind): SecretPurpose
    {
        return match ([$product, $kind]) {
            [OnboardingProduct::Pve, OnboardingCredentialKind::Scan] => SecretPurpose::PveCollectorToken,
            [OnboardingProduct::Pve, OnboardingCredentialKind::Backup] => SecretPurpose::PveBackupToken,
            [OnboardingProduct::Pbs, OnboardingCredentialKind::Scan] => SecretPurpose::PbsCollectorToken,
            default => throw new RuntimeException('The onboarding credential purpose is invalid.'),
        };
    }

    private function writeEndpointEvidence(
        OnboardingActivationCommand $command,
        OnboardingVerification $verification,
        string $endpointId,
        string $now,
    ): void {
        [$ca, $fingerprint] = $this->trustMaterial($command);
        $values = [
            'connection_id' => $command->connectionId,
            'endpoint_config_hash' => $this->endpointConfigHash(
                $command->product,
                strtolower($command->endpoint->host),
                $command->endpoint->port,
                $command->endpoint->tlsMode->value,
                $ca,
                $fingerprint,
            ),
            'detected_product' => $verification->detectedProduct?->value,
            'detected_version' => $verification->detectedVersion,
            'scan_permissions_verified' => (int) $verification->scanPermissionsVerified,
            'backup_permissions_verified' => null === $verification->backupPermissionsVerified
                ? null
                : (int) $verification->backupPermissionsVerified,
            'warnings_json' => $this->warningsJson($verification),
            'verified_at' => $now,
        ];
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM proxmox_endpoint_onboarding_evidence WHERE connection_id = ? AND endpoint_id = ? FOR UPDATE',
            [$command->connectionId, $endpointId],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
        if (false === $exists) {
            $this->connection->insert(
                'proxmox_endpoint_onboarding_evidence',
                ['endpoint_id' => $endpointId] + $values,
                [
                    'endpoint_id' => ParameterType::BINARY,
                    'connection_id' => ParameterType::BINARY,
                    'endpoint_config_hash' => ParameterType::BINARY,
                ],
            );
        } else {
            $this->connection->update(
                'proxmox_endpoint_onboarding_evidence',
                $values,
                ['connection_id' => $command->connectionId, 'endpoint_id' => $endpointId],
                [
                    'connection_id' => ParameterType::BINARY,
                    'endpoint_id' => ParameterType::BINARY,
                    'endpoint_config_hash' => ParameterType::BINARY,
                ],
            );
        }
    }

    private function endpointConfigHash(
        OnboardingProduct $product,
        string $host,
        int $port,
        string $tlsMode,
        ?string $customCa,
        ?string $fingerprint,
    ): string {
        return hash('sha256', json_encode([
            'product' => $product->value,
            'host' => strtolower($host),
            'port' => $port,
            'tlsMode' => $tlsMode,
            'customCaPem' => $customCa,
            'sha256Fingerprint' => null === $fingerprint ? null : bin2hex($fingerprint),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }

    private function warningsJson(OnboardingVerification $verification): string
    {
        return json_encode(array_values(array_map(
            static fn (OnboardingVerificationIssue $issue): array => $issue->toArray(),
            array_filter($verification->issues, static fn (OnboardingVerificationIssue $issue): bool => OnboardingIssueSeverity::Warning === $issue->severity),
        )), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function writeOnboardingState(OnboardingActivationCommand $command, OnboardingVerification $verification, string $now): void
    {
        $values = [
            'state' => 'first_automatic_scan_pending',
            'tls_verified' => 1,
            'product_supported' => 1,
            'scan_permissions_verified' => 1,
            'backup_permissions_verified' => $verification->backupPermissionsVerified,
            'detected_product' => $verification->detectedProduct?->value,
            'detected_version' => $verification->detectedVersion,
            'warnings_json' => $this->warningsJson($verification),
            'verified_at' => $now,
            'inventory_status_changed_at' => $now,
            'last_inventory_run_id' => null,
        ];
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM proxmox_connection_onboarding_state WHERE connection_id = ? FOR UPDATE',
            [$command->connectionId],
            [ParameterType::BINARY],
        );
        if (false === $exists) {
            $this->connection->insert('proxmox_connection_onboarding_state', ['connection_id' => $command->connectionId] + $values, ['connection_id' => ParameterType::BINARY]);
        } else {
            $this->connection->update('proxmox_connection_onboarding_state', $values, ['connection_id' => $command->connectionId], ['connection_id' => ParameterType::BINARY]);
        }
    }

    private function endpointMatches(OnboardingActivationCommand $command): bool
    {
        if (null === $command->endpointId) {
            return false;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT host, port, tls_mode, custom_ca_pem, sha256_fingerprint FROM proxmox_connection_endpoints WHERE connection_id = ? AND id = ? AND enabled = 1 FOR UPDATE',
            [$command->connectionId, $command->endpointId],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
        if (false === $row) {
            return false;
        }
        [$ca, $fingerprint] = $this->trustMaterial($command);

        return $row['host'] === strtolower($command->endpoint->host)
            && $this->integer($row['port']) === $command->endpoint->port
            && $row['tls_mode'] === $command->endpoint->tlsMode->value
            && $row['custom_ca_pem'] === $ca
            && $row['sha256_fingerprint'] === $fingerprint;
    }

    /** @return array{?string, ?string} */
    private function trustMaterial(OnboardingActivationCommand $command): array
    {
        if (null !== $command->endpoint->customCaPem) {
            $ca = OnboardingProduct::Pve === $command->product
                ? PveCustomCaCertificate::fromPem($command->endpoint->customCaPem)->pem
                : PbsCustomCaCertificate::fromPem($command->endpoint->customCaPem)->pem;

            return [$ca, null];
        }
        if (null !== $command->endpoint->sha256Fingerprint) {
            return [null, hex2bin($command->endpoint->sha256Fingerprint) ?: throw new RuntimeException('The onboarding fingerprint is invalid.')];
        }

        return [null, null];
    }

    private function idempotency(
        OnboardingActivationCommand $command,
        AuthenticatedPrincipal $principal,
        PlaintextSecret $replaySecret,
        bool $lock,
    ): ?OnboardingMutationResult {
        $row = $this->connection->fetchAssociative(
            'SELECT connection_id, payload_hash, secret_replay_hash, result_status, result_revision, verification_json FROM proxmox_onboarding_commands WHERE actor_user_id = ? AND idempotency_key = ?'.($lock ? ' FOR UPDATE' : ''),
            [$principal->userId->binary(), $command->idempotencyKey],
            [ParameterType::BINARY, ParameterType::STRING],
        );
        if (false === $row) {
            return null;
        }
        $matches = hash_equals($this->binary($row['payload_hash']), $command->payloadHash)
            && $this->replayHasher->verify($replaySecret, new PasswordHash($this->text($row['secret_replay_hash'])));
        if (!$matches) {
            return new OnboardingMutationResult(
                OnboardingMutationStatus::Conflict,
                $command->connectionId,
                $this->currentRevision($command->connectionId),
            );
        }
        $revision = null === $row['result_revision'] ? null : $this->integer($row['result_revision']);
        $verification = null === $row['verification_json'] ? null : $this->verification($this->text($row['verification_json']));
        $status = OnboardingMutationStatus::from($this->text($row['result_status']));
        if (OnboardingMutationStatus::Applied === $status || OnboardingMutationStatus::Replayed === $status) {
            $status = OnboardingMutationStatus::Replayed;
        }

        return new OnboardingMutationResult($status, $this->binary($row['connection_id']), $revision, $verification);
    }

    private function persistIdempotency(
        OnboardingActivationCommand $command,
        AuthenticatedPrincipal $principal,
        OnboardingMutationResult $result,
        PlaintextSecret $replaySecret,
    ): void {
        $this->connection->insert('proxmox_onboarding_commands', [
            'actor_user_id' => $principal->userId->binary(),
            'idempotency_key' => $command->idempotencyKey,
            'connection_id' => $command->connectionId,
            'mode' => $command->mode->value,
            'payload_hash' => $command->payloadHash,
            'secret_replay_hash' => $this->replayHasher->hash($replaySecret)->encoded(),
            'result_status' => OnboardingMutationStatus::Replayed === $result->status ? 'applied' : $result->status->value,
            'result_revision' => $result->revision,
            'verification_json' => null === $result->verification ? null : $this->verificationJson($result->verification),
            'created_at' => $this->now(),
        ], [
            'actor_user_id' => ParameterType::BINARY,
            'connection_id' => ParameterType::BINARY,
            'payload_hash' => ParameterType::BINARY,
        ]);
    }

    private function audit(OnboardingActivationCommand $command, AuthenticatedPrincipal $principal, OnboardingMutationResult $result): void
    {
        $this->connection->insert('audit_events', [
            'id' => $this->ids->generate(),
            'occurred_at' => $this->now(),
            'actor_user_id' => $principal->userId->binary(),
            'actor_session_id' => $principal->sessionId,
            'event_type' => match ($command->mode) {
                OnboardingMode::Activate => 'connection_created',
                OnboardingMode::Rotate => 'credential_rotated',
                OnboardingMode::EndpointAdd => 'endpoint_created',
                OnboardingMode::EndpointUpdate => 'endpoint_updated',
            },
            'outcome' => OnboardingMutationStatus::Applied === $result->status ? 'succeeded' : 'denied',
            'subject_type' => 'connection',
            'subject_id' => $command->connectionId,
            'reason_code' => match ($result->status) {
                OnboardingMutationStatus::Applied, OnboardingMutationStatus::Replayed => null,
                OnboardingMutationStatus::Conflict => 'revision_conflict',
                OnboardingMutationStatus::Rejected => 'verification_failed',
                OnboardingMutationStatus::Denied => 'permission_denied',
            },
            'correlation_id' => $command->correlationId,
        ], [
            'id' => ParameterType::BINARY,
            'actor_user_id' => ParameterType::BINARY,
            'actor_session_id' => ParameterType::BINARY,
            'subject_id' => ParameterType::BINARY,
            'correlation_id' => ParameterType::BINARY,
        ]);
    }

    private function replaySecret(OnboardingActivationCommand $command): PlaintextSecret
    {
        $bytes = '';
        foreach ($command->credentials as $credential) {
            $credential->secret->consume(static function (string $secret) use (&$bytes, $credential): void {
                $kind = $credential->kind->value;
                $bytes .= pack('N', strlen($kind)).$kind.pack('N', strlen($secret)).$secret;
            });
        }
        $digest = hash('sha256', $bytes);
        $bytes = str_repeat("\0", strlen($bytes));
        unset($bytes);
        $secret = PlaintextSecret::fromString($digest);
        unset($digest);

        return $secret;
    }

    private function verificationJson(OnboardingVerification $verification): string
    {
        return json_encode([
            'tlsVerified' => $verification->tlsVerified,
            'productSupported' => $verification->productSupported,
            'scanPermissionsVerified' => $verification->scanPermissionsVerified,
            'backupPermissionsVerified' => $verification->backupPermissionsVerified,
            'detectedProduct' => $verification->detectedProduct?->value,
            'detectedVersion' => $verification->detectedVersion,
            'issues' => array_map(static fn (OnboardingVerificationIssue $issue): array => $issue->toArray(), $verification->issues),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function verification(string $json): OnboardingVerification
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Persisted onboarding verification evidence is invalid.');
        }
        $persistedIssues = $data['issues'] ?? null;
        if (!is_array($persistedIssues)) {
            throw new RuntimeException('Persisted onboarding verification evidence is invalid.');
        }
        $issues = [];
        foreach ($persistedIssues as $issue) {
            if (!is_array($issue)) {
                throw new RuntimeException('Persisted onboarding verification evidence is invalid.');
            }
            $issues[] = new OnboardingVerificationIssue(
                OnboardingIssueCode::from($this->text($issue['code'] ?? null)),
                OnboardingIssueSeverity::from($this->text($issue['severity'] ?? null)),
                null === ($issue['credential'] ?? null) ? null : OnboardingCredentialKind::from($this->text($issue['credential'])),
                null === ($issue['path'] ?? null) ? null : $this->text($issue['path']),
                null === ($issue['privilege'] ?? null) ? null : $this->text($issue['privilege']),
            );
        }

        return new OnboardingVerification(
            true === ($data['tlsVerified'] ?? null),
            true === ($data['productSupported'] ?? null),
            true === ($data['scanPermissionsVerified'] ?? null),
            null === ($data['backupPermissionsVerified'] ?? null) ? null : true === $data['backupPermissionsVerified'],
            null === ($data['detectedProduct'] ?? null) ? null : OnboardingProduct::from($this->text($data['detectedProduct'])),
            null === ($data['detectedVersion'] ?? null) ? null : $this->text($data['detectedVersion']),
            $issues,
        );
    }

    private function currentRevision(string $connectionId): int
    {
        $value = $this->connection->fetchOne('SELECT revision FROM proxmox_connections WHERE id = ?', [$connectionId], [ParameterType::BINARY]);

        return false === $value ? 0 : $this->integer($value);
    }

    /** @return array{string, string} */
    private function tokenParts(string $tokenId): array
    {
        $at = strrpos($tokenId, '!');
        if (false === $at) {
            throw new RuntimeException('The validated onboarding token identifier is invalid.');
        }

        return [substr($tokenId, 0, $at), substr($tokenId, $at + 1)];
    }

    private function purpose(OnboardingCredential $credential): string
    {
        return OnboardingCredentialKind::Scan === $credential->kind ? 'collector' : 'backup';
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::DB_DATE);
    }

    private function binary(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('A persisted binary onboarding value is invalid.');
        }

        return $value;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('A persisted onboarding text is invalid.');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value))) {
            throw new RuntimeException('A persisted onboarding integer is invalid.');
        }

        return (int) $value;
    }
}
