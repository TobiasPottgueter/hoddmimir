<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Configuration\Connection\ConnectionCommandRepository;
use App\Application\Configuration\Connection\ConnectionOnboardingState;
use App\Application\Configuration\Connection\ConnectionOnboardingStatus;
use App\Application\Configuration\Connection\ConnectionReadModel;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\Auth\PasswordHasher;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Domain\Shared\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use RuntimeException;

final readonly class DbalConnectionAdministration implements ConnectionCommandRepository, ConnectionReadModel
{
    private const string DB_DATE = 'Y-m-d H:i:s.u';

    public function __construct(
        private Connection $connection,
        private PasswordHasher $replayHasher,
        private Clock $clock,
        private SecurityIdentifierGenerator $ids,
    ) {
    }

    public function execute(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult
    {
        try {
            return $this->connection->transactional(function () use ($command, $principal): ConfigurationCommandResult {
                $existing = $this->idempotency($command, $principal, true);
                if (null !== $existing) {
                    return $existing;
                }
                $result = match ($command->type) {
                    ConfigurationCommandType::ConnectionCreate,
                    ConfigurationCommandType::ConnectionEnable,
                    ConfigurationCommandType::EndpointCreate,
                    ConfigurationCommandType::EndpointUpdate,
                    ConfigurationCommandType::CredentialRotate => ConfigurationCommandResult::blocked('verified_onboarding_required'),
                    ConfigurationCommandType::ConnectionUpdate => $this->updateConnection($command),
                    ConfigurationCommandType::ConnectionDisable => $this->disableConnection($command),
                    ConfigurationCommandType::EndpointDisable => $this->disableEndpoint($command),
                    default => throw new InvalidArgumentException('An unrelated command reached connection persistence.'),
                };
                $this->persistIdempotency($command, $principal, $result);
                $this->audit($command, $principal, $result);
                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->idempotency($command, $principal, false)
                ?? throw new RuntimeException('The connection idempotency race could not be resolved.');
        }
    }

    public function record(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): ConfigurationCommandResult
    {
        return $this->connection->transactional(function () use ($command, $principal, $result): ConfigurationCommandResult {
            $existing = $this->idempotency($command, $principal, true);
            if (null !== $existing) {
                return $existing;
            }
            $this->persistIdempotency($command, $principal, $result);
            $this->audit($command, $principal, $result);
            return $result;
        });
    }

    public function connections(PageRequest $page): array
    {
        $context = PageCursor::context('connections-v1');
        $where = '';
        $parameters = [];
        $types = [];
        if (null !== $page->cursor) {
            $page->cursor->assertContext(PageCursorKind::Resource, $context);
            $where = ' WHERE (display_name > ? OR (display_name = ? AND id > ?))';
            $parameters = [$page->cursor->first, $page->cursor->first, (new ReadModelIdentifier($page->cursor->second))->binary()];
            $types = [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY];
        }
        $parameters[] = $page->limit + 1;
        $types[] = ParameterType::INTEGER;
        $rows = $this->connection->fetchAllAssociative('SELECT id,display_name,product,enabled,revision,created_at,updated_at FROM proxmox_connections'.$where.' ORDER BY display_name,id LIMIT ?', $parameters, $types);
        $hasMore = count($rows) > $page->limit;
        if ($hasMore) array_pop($rows);
        /** @var list<array<string, mixed>> $items */
        $items = array_map(fn (array $row): array => $this->connectionView($row, false), $rows);
        $last = [] === $items ? null : $items[array_key_last($items)];
        $next = $hasMore && is_array($last)
            ? PageCursor::resource($context, $this->text($last['displayName']), $this->text($last['id']))
            : null;
        return [
            'items' => $items,
            'page' => ['limit' => $page->limit, 'count' => count($items), 'hasMore' => null !== $next, 'nextCursor' => $next?->opaque()],
        ];
    }

    public function connection(string $id): ?array
    {
        $binary = $this->binaryUuid($id);
        $row = $this->connection->fetchAssociative('SELECT id,display_name,product,enabled,revision,created_at,updated_at FROM proxmox_connections WHERE id=?', [$binary], [ParameterType::BINARY]);
        if (false === $row) return null;
        /** @var array<string, mixed> $row */
        return $this->connectionView($row, true);
    }

    private function updateConnection(ConfigurationCommand $command): ConfigurationCommandResult
    {
        $row = $this->lockConnection($command);
        if ($row instanceof ConfigurationCommandResult) {
            return $row;
        }
        $next = $this->integer($row['revision']) + 1;
        $this->connection->update('proxmox_connections', [
            'display_name' => $this->boundedText($command->payload['displayName'] ?? null, 190),
            'revision' => $next, 'updated_at' => $this->now(),
        ], ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, $next);
    }

    private function disableConnection(ConfigurationCommand $command): ConfigurationCommandResult
    {
        $row = $this->lockConnection($command);
        if ($row instanceof ConfigurationCommandResult) {
            return $row;
        }
        $next = $this->integer($row['revision']) + 1;
        $this->connection->update('proxmox_connections', ['enabled' => 0, 'revision' => $next, 'updated_at' => $this->now()], ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, $next);
    }

    private function disableEndpoint(ConfigurationCommand $command): ConfigurationCommandResult
    {
        $row = $this->lockConnection($command);
        if ($row instanceof ConfigurationCommandResult) {
            return $row;
        }
        $endpointId = $this->binaryValue($command->payload['endpointId'] ?? null);
        $endpoint = $this->connection->fetchAssociative(<<<'SQL'
SELECT endpoint.enabled, evidence.endpoint_id AS verified_endpoint_id
FROM proxmox_connection_endpoints endpoint
LEFT JOIN proxmox_endpoint_onboarding_evidence evidence
  ON evidence.connection_id = endpoint.connection_id AND evidence.endpoint_id = endpoint.id
WHERE endpoint.connection_id = ? AND endpoint.id = ?
FOR UPDATE
SQL, [$command->subjectId, $endpointId], [ParameterType::BINARY, ParameterType::BINARY]);
        if (false === $endpoint) {
            return ConfigurationCommandResult::blocked('endpoint_missing');
        }
        $enabledCount = $this->integer($this->connection->fetchOne('SELECT COUNT(*) FROM proxmox_connection_endpoints WHERE connection_id=? AND enabled=1', [$command->subjectId], [ParameterType::BINARY]));
        if (1 === $this->integer($endpoint['enabled']) && $enabledCount === 1) {
            return ConfigurationCommandResult::blocked('last_enabled_endpoint');
        }
        if (1 === $this->integer($row['enabled'])) {
            if (1 !== $this->integer($endpoint['enabled'])) {
                return ConfigurationCommandResult::blocked('connection_must_be_disabled');
            }
            if (!is_string($endpoint['verified_endpoint_id'])) {
                return ConfigurationCommandResult::blocked('verified_onboarding_required');
            }
        }
        $this->connection->update('proxmox_connection_endpoints', ['enabled' => 0, 'updated_at' => $this->now()], ['connection_id' => $command->subjectId, 'id' => $endpointId], ['connection_id' => ParameterType::BINARY, 'id' => ParameterType::BINARY]);
        return $this->bump($command->subjectId, $this->integer($row['revision']));
    }

    /** @return array<string, mixed>|ConfigurationCommandResult */
    private function lockConnection(ConfigurationCommand $command): array|ConfigurationCommandResult
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM proxmox_connections WHERE id=? FOR UPDATE', [$command->subjectId], [ParameterType::BINARY]);
        if (false === $row) {
            return ConfigurationCommandResult::blocked('connection_missing');
        }
        $revision = $this->integer($row['revision']);
        return $revision === $command->expectedRevision ? $row : new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, $revision);
    }

    private function bump(string $connectionId, int $revision): ConfigurationCommandResult
    {
        $next = $revision + 1;
        $this->connection->update('proxmox_connections', ['revision' => $next, 'updated_at' => $this->now()], ['id' => $connectionId], ['id' => ParameterType::BINARY]);
        return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied, $next);
    }

    private function idempotency(ConfigurationCommand $command, AuthenticatedPrincipal $principal, bool $lock): ?ConfigurationCommandResult
    {
        $row = $this->connection->fetchAssociative('SELECT payload_hash,secret_replay_hash,result_status,result_revision,blocker_code FROM configuration_command_idempotency WHERE actor_user_id=? AND idempotency_key=?'.($lock ? ' FOR UPDATE' : ''), [$principal->userId->binary(), $command->idempotencyKey], [ParameterType::BINARY, ParameterType::STRING]);
        if (false === $row) return null;
        $revision = null === $row['result_revision'] ? null : $this->integer($row['result_revision']);
        $secretMatches = null === $command->secret
            ? null === $row['secret_replay_hash']
            : is_string($row['secret_replay_hash']) && $this->replayHasher->verify($command->secret, new PasswordHash($row['secret_replay_hash']));
        if (!hash_equals($this->binaryValue($row['payload_hash']), $command->payloadHash) || !$secretMatches) {
            return new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, $revision ?? 0);
        }
        return match ($this->text($row['result_status'])) {
            'applied' => new ConfigurationCommandResult(ConfigurationCommandStatus::Replayed, $revision),
            'conflict' => new ConfigurationCommandResult(ConfigurationCommandStatus::Conflict, $revision),
            'blocked' => ConfigurationCommandResult::blocked($this->text($row['blocker_code'])),
            'denied' => ConfigurationCommandResult::denied(),
            default => throw new RuntimeException('A persisted connection command result is invalid.'),
        };
    }

    private function persistIdempotency(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): void
    {
        $this->connection->insert('configuration_command_idempotency', [
            'actor_user_id' => $principal->userId->binary(), 'idempotency_key' => $command->idempotencyKey,
            'command_type' => $command->type->value, 'subject_id' => $command->subjectId, 'payload_hash' => $command->payloadHash,
            'secret_replay_hash' => null === $command->secret ? null : $this->replayHasher->hash($command->secret)->encoded(),
            'result_status' => ConfigurationCommandStatus::Replayed === $result->status ? 'applied' : $result->status->value,
            'result_revision' => $result->revision, 'blocker_code' => $result->blockers[0] ?? null, 'created_at' => $this->now(),
        ], ['actor_user_id' => ParameterType::BINARY, 'subject_id' => ParameterType::BINARY, 'payload_hash' => ParameterType::BINARY]);
    }

    private function audit(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): void
    {
        $this->connection->insert('audit_events', [
            'id' => $this->ids->generate(), 'occurred_at' => $this->now(), 'actor_user_id' => $principal->userId->binary(), 'actor_session_id' => $principal->sessionId,
            'event_type' => $command->type->auditType()->value, 'outcome' => ConfigurationCommandStatus::Applied === $result->status ? 'succeeded' : 'denied',
            'subject_type' => 'connection', 'subject_id' => $command->subjectId,
            'reason_code' => $result->blockers[0] ?? (ConfigurationCommandStatus::Conflict === $result->status ? 'revision_conflict' : null),
            'correlation_id' => $command->correlationId,
        ], ['id' => ParameterType::BINARY, 'actor_user_id' => ParameterType::BINARY, 'actor_session_id' => ParameterType::BINARY, 'subject_id' => ParameterType::BINARY, 'correlation_id' => ParameterType::BINARY]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function connectionView(array $row, bool $details): array
    {
        $id = $this->binaryValue($row['id']);
        /** @var array<string, mixed> $view */
        $view = [
            'id' => $this->uuid($id), 'displayName' => $this->text($row['display_name']), 'product' => $this->text($row['product']),
            'enabled' => 1 === $this->integer($row['enabled']), 'revision' => $this->integer($row['revision']),
            'createdAt' => $this->apiDate($row['created_at']), 'updatedAt' => $this->apiDate($row['updated_at']),
        ];
        $capability = $this->connection->fetchAssociative(
            'SELECT product,version_major,version_minor,version_patch,raw_version,last_observed_at FROM proxmox_capability_snapshots WHERE connection_id=? ORDER BY last_observed_at DESC,id DESC LIMIT 1',
            [$id],
            [ParameterType::BINARY],
        );
        if (false === $capability) {
            $view['detectedVersion'] = null;
            $view['versionSupportStatus'] = 'unknown';
        } else {
            $product = $this->text($capability['product']);
            $major = $this->integer($capability['version_major']);
            $view['detectedVersion'] = $this->text($capability['raw_version']);
            $view['versionSupportStatus'] = $product === $view['product'] && (
                ('pve' === $product && $major >= 7 && $major <= 9)
                || ('pbs' === $product && $major >= 3 && $major <= 4)
            ) ? 'supported' : 'unsupported';
        }
        if (!$details) return $view;
        $endpoints = $this->connection->fetchAllAssociative('SELECT id,host,port,priority,enabled,tls_mode,custom_ca_pem,sha256_fingerprint,last_attempted_at,last_success_at,last_error_code FROM proxmox_connection_endpoints WHERE connection_id=? ORDER BY priority,id', [$id], [ParameterType::BINARY]);
        $view['endpoints'] = array_map(fn (array $endpoint): array => [
            'id' => $this->uuid($this->binaryValue($endpoint['id'])), 'host' => $this->text($endpoint['host']),
            'port' => $this->integer($endpoint['port']), 'priority' => $this->integer($endpoint['priority']),
            'enabled' => 1 === $this->integer($endpoint['enabled']), 'tlsMode' => $this->text($endpoint['tls_mode']),
            'customCaConfigured' => null !== $endpoint['custom_ca_pem'],
            'sha256Fingerprint' => null === $endpoint['sha256_fingerprint'] ? null : bin2hex($this->binaryValue($endpoint['sha256_fingerprint'])),
            'lastAttemptedAt' => $this->apiNullableDate($endpoint['last_attempted_at']), 'lastSuccessAt' => $this->apiNullableDate($endpoint['last_success_at']),
            'lastErrorCode' => null === $endpoint['last_error_code'] ? null : $this->text($endpoint['last_error_code']),
        ], $endpoints);
        $credentials = $this->connection->fetchAllAssociative('SELECT purpose,principal,token_name,revision,rotated_at,updated_at FROM proxmox_credentials WHERE connection_id=? ORDER BY purpose', [$id], [ParameterType::BINARY]);
        $view['credentials'] = array_map(fn (array $credential): array => [
            'purpose' => $this->text($credential['purpose']), 'principal' => $this->text($credential['principal']),
            'tokenName' => $this->text($credential['token_name']), 'configured' => true,
            'revision' => $this->integer($credential['revision']), 'rotatedAt' => $this->apiNullableDate($credential['rotated_at']),
            'updatedAt' => $this->apiDate($credential['updated_at']),
        ], $credentials);
        $onboarding = $this->connection->fetchAssociative(
            'SELECT state,verified_at,inventory_status_changed_at,last_inventory_run_id FROM proxmox_connection_onboarding_state WHERE connection_id=?',
            [$id],
            [ParameterType::BINARY],
        );
        $view['onboardingState'] = false === $onboarding ? null : (new ConnectionOnboardingState(
            ConnectionOnboardingStatus::from($this->text($onboarding['state'])),
            $this->apiDate($onboarding['verified_at']),
            $this->apiDate($onboarding['inventory_status_changed_at']),
            null === $onboarding['last_inventory_run_id']
                ? null
                : $this->uuid($this->binaryValue($onboarding['last_inventory_run_id'])),
        ))->toArray();
        return $view;
    }

    private function boundedText(mixed $value, int $max): string { if (!is_string($value) || $value === '' || trim($value) !== $value || strlen($value) > $max) throw new InvalidArgumentException('A command text value is invalid.'); return $value; }
    private function binaryValue(mixed $value): string { if (!is_string($value)) throw new RuntimeException('A persisted binary value is invalid.'); return $value; }
    private function text(mixed $value): string { if (!is_string($value)) throw new RuntimeException('A persisted text value is invalid.'); return $value; }
    private function integer(mixed $value): int { if (!is_int($value) && (!is_string($value) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value))) throw new RuntimeException('A persisted integer is invalid.'); $integer=(int)$value; if ((string)$integer !== (string)$value) throw new RuntimeException('A persisted integer is out of range.'); return $integer; }
    private function now(): string { return $this->clock->now()->format(self::DB_DATE); }
    private function apiDate(mixed $value): string { return str_replace(' ', 'T', $this->text($value)).'Z'; }
    private function apiNullableDate(mixed $value): ?string { return null === $value ? null : $this->apiDate($value); }
    private function uuid(string $binary): string { $hex=bin2hex($binary); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20); }
    private function binaryUuid(string $value): string { if (1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $value)) throw new InvalidArgumentException('A canonical UUID is required.'); return hex2bin(str_replace('-', '', $value)) ?: throw new InvalidArgumentException('A canonical UUID is required.'); }
}
