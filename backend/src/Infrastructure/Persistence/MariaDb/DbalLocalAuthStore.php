<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Security\Audit\AuditEventStore;
use App\Application\Security\Audit\SecurityAuditEvent;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthenticationIdentity;
use App\Application\Security\Auth\AuthenticationStore;
use App\Application\Security\Auth\ExistingAdmin;
use App\Application\Security\Auth\FirstAdminStore;
use App\Application\Security\Auth\LoginThrottleStore;
use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\Auth\SecurityDigest;
use App\Application\Security\Auth\SecurityTransaction;
use App\Application\Security\Auth\StoredWebSession;
use App\Application\Security\Auth\WebSessionStore;
use App\Domain\Security\LoginThrottleState;
use App\Domain\Security\GlobalLoginThrottleState;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserDisplayName;
use App\Domain\Security\UserId;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalLocalAuthStore implements AuthenticationStore, LoginThrottleStore, WebSessionStore, AuditEventStore, SecurityTransaction, FirstAdminStore
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private Connection $connection)
    {
    }

    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }

    public function findEnabled(NormalizedUsername $username): ?AuthenticationIdentity
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, username, password_hash FROM users WHERE username = ? AND enabled = 1',
            [$username->value],
        );
        if (false === $row) {
            return null;
        }

        $id = $this->binary($row['id']);

        return new AuthenticationIdentity(
            new UserId($id),
            new NormalizedUsername($this->text($row['username'])),
            new PasswordHash($this->text($row['password_hash'])),
            $this->permissions($id),
        );
    }

    public function performMaintenance(DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('CALL prune_login_security_state(?, ?)', [
            $this->format($now->modify('-30 minutes')),
            $this->format($now->modify('-30 days')),
        ]);
    }

    public function lockGlobalThrottle(): GlobalLoginThrottleState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT window_started_at, failure_count, last_failed_at, locked_until FROM login_global_throttle WHERE singleton_id = 1 FOR UPDATE',
        );
        if (false === $row) {
            throw new RuntimeException('The global login throttle is unavailable.');
        }

        return new GlobalLoginThrottleState(
            $this->date($row['window_started_at']),
            $this->integer($row['failure_count']),
            $this->date($row['last_failed_at']),
            null === $row['locked_until'] ? null : $this->date($row['locked_until']),
        );
    }

    public function saveGlobalThrottle(GlobalLoginThrottleState $state): void
    {
        if (1 !== $this->connection->update('login_global_throttle', [
            'window_started_at' => $this->format($state->windowStartedAt),
            'failure_count' => $state->failureCount,
            'last_failed_at' => $this->format($state->lastFailedAt),
            'locked_until' => null === $state->lockedUntil ? null : $this->format($state->lockedUntil),
            'updated_at' => $this->format($state->lastFailedAt),
        ], ['singleton_id' => 1])) {
            throw new RuntimeException('The global login throttle changed unexpectedly.');
        }
    }

    public function lockIpThrottle(string $packedIp): ?LoginThrottleState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT window_started_at, failure_count, last_failed_at, locked_until FROM login_ip_attempts WHERE ip_address = ? FOR UPDATE',
            [$packedIp],
            [ParameterType::BINARY],
        );
        if (false === $row) {
            return null;
        }

        return new LoginThrottleState(
            $this->date($row['window_started_at']),
            $this->integer($row['failure_count']),
            $this->date($row['last_failed_at']),
            null === $row['locked_until'] ? null : $this->date($row['locked_until']),
        );
    }

    public function saveIpThrottle(string $packedIp, LoginThrottleState $state): void
    {
        $this->connection->executeStatement(
            'INSERT INTO login_ip_attempts (ip_address, window_started_at, failure_count, last_failed_at, locked_until, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE window_started_at = VALUES(window_started_at), failure_count = VALUES(failure_count), last_failed_at = VALUES(last_failed_at), locked_until = VALUES(locked_until), updated_at = VALUES(updated_at)',
            [$packedIp, $this->format($state->windowStartedAt), $state->failureCount, $this->format($state->lastFailedAt), $state->lockedUntil?->format(self::DATE_FORMAT), $this->format($state->lastFailedAt)],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function clearIpThrottle(string $packedIp): void
    {
        $this->connection->delete('login_ip_attempts', ['ip_address' => $packedIp], ['ip_address' => ParameterType::BINARY]);
    }

    public function lockThrottle(NormalizedUsername $key, string $packedIp): ?LoginThrottleState
    {
        $row = $this->connection->fetchAssociative(
                'SELECT window_started_at, failure_count, last_failed_at, locked_until FROM login_attempts WHERE username = ? AND ip_address = ? FOR UPDATE',
                [$key->value, $packedIp],
                [ParameterType::STRING, ParameterType::BINARY],
            );
        if (false === $row) {
            return null;
        }

        return new LoginThrottleState(
                $this->date($row['window_started_at']),
                $this->integer($row['failure_count']),
                $this->date($row['last_failed_at']),
                null === $row['locked_until'] ? null : $this->date($row['locked_until']),
        );
    }

    public function lockSession(SecurityDigest $key): ?StoredWebSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT s.id, s.user_id, s.csrf_secret_hash, s.issued_at, s.last_seen_at, s.idle_expires_at, s.absolute_expires_at, s.revoked_at, u.username FROM web_sessions s INNER JOIN users u ON u.id = s.user_id AND u.enabled = 1 WHERE s.token_hash = ? FOR UPDATE',
            [$key->binary()],
            [ParameterType::BINARY],
        );
        if (false === $row) {
            return null;
        }
        $userId = $this->binary($row['user_id']);

        return new StoredWebSession(
            $this->binary($row['id']),
            new AuthenticatedPrincipal(new UserId($userId), new NormalizedUsername($this->text($row['username'])), $this->permissions($userId)),
            new SecurityDigest($this->binary($row['csrf_secret_hash'])),
            new SessionWindow(
                $this->date($row['issued_at']),
                $this->date($row['last_seen_at']),
                $this->date($row['idle_expires_at']),
                $this->date($row['absolute_expires_at']),
            ),
            null === $row['revoked_at'] ? null : $this->date($row['revoked_at']),
        );
    }

    public function save(NormalizedUsername $username, string $packedIp, LoginThrottleState $state): void
    {
        $this->connection->executeStatement(
            'INSERT INTO login_attempts (username, ip_address, window_started_at, failure_count, last_failed_at, locked_until, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE window_started_at = VALUES(window_started_at), failure_count = VALUES(failure_count), last_failed_at = VALUES(last_failed_at), locked_until = VALUES(locked_until), updated_at = VALUES(updated_at)',
            [$username->value, $packedIp, $this->format($state->windowStartedAt), $state->failureCount, $this->format($state->lastFailedAt), $state->lockedUntil?->format(self::DATE_FORMAT), $this->format($state->lastFailedAt)],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function clear(NormalizedUsername $username, string $packedIp): void
    {
        $this->connection->executeStatement(
            'DELETE FROM login_attempts WHERE username = ? AND ip_address = ?',
            [$username->value, $packedIp],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }

    public function create(string $sessionId, UserId $userId, SecurityDigest $tokenHash, SecurityDigest $csrfHash, SessionWindow $window): void
    {
        $this->connection->insert('web_sessions', [
            'id' => $sessionId,
            'user_id' => $userId->binary(),
            'token_hash' => $tokenHash->binary(),
            'csrf_secret_hash' => $csrfHash->binary(),
            'issued_at' => $this->format($window->issuedAt),
            'last_seen_at' => $this->format($window->lastSeenAt),
            'idle_expires_at' => $this->format($window->idleExpiresAt),
            'absolute_expires_at' => $this->format($window->absoluteExpiresAt),
        ], [
            'id' => ParameterType::BINARY,
            'user_id' => ParameterType::BINARY,
            'token_hash' => ParameterType::BINARY,
            'csrf_secret_hash' => ParameterType::BINARY,
        ]);
    }

    public function touch(SecurityDigest $tokenHash, SessionWindow $window): void
    {
        $this->connection->executeStatement(
            'UPDATE web_sessions SET last_seen_at = ?, idle_expires_at = ? WHERE token_hash = ? AND revoked_at IS NULL',
            [$this->format($window->lastSeenAt), $this->format($window->idleExpiresAt), $tokenHash->binary()],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
        );
    }

    public function revoke(SecurityDigest $tokenHash, DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE web_sessions SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL',
            [$this->format($at), $tokenHash->binary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }

    public function append(SecurityAuditEvent $event): void
    {
        $this->connection->insert('audit_events', [
            'id' => $event->id,
            'occurred_at' => $this->format($event->occurredAt),
            'actor_user_id' => $event->actorUserId?->binary(),
            'actor_session_id' => $event->actorSessionId,
            'event_type' => $event->type->value,
            'outcome' => $event->outcome->value,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'reason_code' => $event->reasonCode,
            'correlation_id' => $event->correlationId,
        ], [
            'id' => ParameterType::BINARY,
            'actor_user_id' => ParameterType::BINARY,
            'actor_session_id' => ParameterType::BINARY,
            'subject_id' => ParameterType::BINARY,
            'correlation_id' => ParameterType::BINARY,
        ]);
    }

    public function countUsers(): int
    {
        return count($this->connection->fetchFirstColumn('SELECT id FROM users FOR UPDATE'));
    }

    public function findAdmin(NormalizedUsername $username): ?ExistingAdmin
    {
        $row = $this->connection->fetchAssociative(
            "SELECT u.id, u.username, u.display_name, u.password_hash FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id AND r.role_name = 'admin' WHERE u.username = ? FOR UPDATE",
            [$username->value],
        );
        if (false === $row) {
            return null;
        }

        return new ExistingAdmin(
            new UserId($this->binary($row['id'])),
            new NormalizedUsername($this->text($row['username'])),
            new UserDisplayName($this->text($row['display_name'])),
            new PasswordHash($this->text($row['password_hash'])),
        );
    }

    public function createAdmin(UserId $id, NormalizedUsername $username, UserDisplayName $displayName, PasswordHash $passwordHash, DateTimeImmutable $at): void
    {
        $time = $this->format($at);
        $this->connection->insert('users', [
            'id' => $id->binary(),
            'username' => $username->value,
            'display_name' => $displayName->value,
            'password_hash' => $passwordHash->encoded(),
            'created_at' => $time,
            'updated_at' => $time,
        ], ['id' => ParameterType::BINARY]);
        $role = $this->connection->fetchOne("SELECT id FROM roles WHERE role_name = 'admin'");
        $this->connection->insert('user_roles', [
            'user_id' => $id->binary(),
            'role_id' => $this->binary($role),
            'assigned_at' => $time,
            'assigned_by_user_id' => $id->binary(),
        ], [
            'user_id' => ParameterType::BINARY,
            'role_id' => ParameterType::BINARY,
            'assigned_by_user_id' => ParameterType::BINARY,
        ]);
    }

    /** @return list<Permission> */
    private function permissions(string $userId): array
    {
        $values = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT p.permission_name FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id = ur.role_id INNER JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ? ORDER BY p.permission_name',
            [$userId],
            [ParameterType::BINARY],
        );

        return array_map(fn (mixed $value): Permission => Permission::from($this->text($value)), $values);
    }

    private function date(mixed $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $this->text($value), new DateTimeZone('UTC'));
        if (false === $date) {
            throw new RuntimeException('A persisted authentication timestamp is invalid.');
        }

        return $date;
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }

    private function binary(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('A persisted authentication binary value is invalid.');
        }

        return $value;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('A persisted authentication value is invalid.');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('A persisted authentication integer is invalid.');
        }
        if (1 !== preg_match('/\A[0-9]+\z/D', (string) $value)) {
            throw new RuntimeException('A persisted authentication integer is invalid.');
        }

        return (int) $value;
    }
}
