<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Administration\ReadModel\AdministrationAuditEvent;
use App\Application\Administration\ReadModel\AdministrationPage;
use App\Application\Administration\ReadModel\AdministrationReadModel;
use App\Application\Administration\ReadModel\AdministrationRole;
use App\Application\Administration\ReadModel\AdministrationUser;
use App\Application\Administration\ReadModel\AuditListQuery;
use App\Application\Administration\ReadModel\UserListQuery;
use App\Application\Administration\SecurityCommand;
use App\Application\Administration\SecurityCommandRepository;
use App\Application\Administration\SecurityCommandResult;
use App\Application\Administration\SecurityCommandStatus;
use App\Application\Administration\SecurityCommandType;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\Auth\PasswordHasher;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Application\Security\Audit\AuditOutcome;
use App\Domain\Shared\Clock;
use App\Domain\Security\LastAdministratorGuard;
use App\Domain\Security\LastAdministratorViolation;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Role;
use App\Domain\Security\UserDisplayName;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalSecurityAdministration implements SecurityCommandRepository, AdministrationReadModel
{
    private const string DB_DATE = 'Y-m-d H:i:s.u';

    public function __construct(
        private Connection $connection,
        private PasswordHasher $passwords,
        private Clock $clock,
        private SecurityIdentifierGenerator $ids,
        private LastAdministratorGuard $lastAdministrator = new LastAdministratorGuard(),
    ) {
    }

    public function execute(SecurityCommand $command, AuthenticatedPrincipal $principal): SecurityCommandResult
    {
        try {
            return $this->connection->transactional(function () use ($command, $principal): SecurityCommandResult {
                $existing = $this->idempotency($command, $principal, true);
                if (null !== $existing) {
                    return $existing;
                }
                $result = match ($command->type) {
                    SecurityCommandType::UserCreate => $this->createUser($command, $principal),
                    SecurityCommandType::UserUpdate => $this->updateUser($command),
                    SecurityCommandType::UserDisable => $this->disableUser($command, $principal),
                    SecurityCommandType::UserRolesReplace => $this->replaceRoles($command, $principal),
                };
                $this->persistIdempotency($command, $principal, $result);
                $this->appendAudit($command, $principal, $result);
                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            $result = $this->idempotency($command, $principal, false);
            if (null === $result) {
                throw new RuntimeException('The security command race could not be resolved.');
            }
            return $result;
        }
    }

    public function record(SecurityCommand $command, AuthenticatedPrincipal $principal, SecurityCommandResult $result): SecurityCommandResult
    {
        try {
            return $this->connection->transactional(function () use ($command, $principal, $result): SecurityCommandResult {
                $existing = $this->idempotency($command, $principal, true);
                if (null !== $existing) {
                    return $existing;
                }
                $this->persistIdempotency($command, $principal, $result);
                $this->appendAudit($command, $principal, $result);
                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->idempotency($command, $principal, false)
                ?? throw new RuntimeException('The denied security command race could not be resolved.');
        }
    }

    public function users(UserListQuery $query): AdministrationPage
    {
        $parameters = [];
        $types = [];
        $where = [];
        if (null !== $query->search) {
            $where[] = '(u.username LIKE ? ESCAPE \'!\' OR u.display_name LIKE ? ESCAPE \'!\')';
            $search = '%'.$this->escapeLike($query->search).'%';
            array_push($parameters, $search, $search);
            array_push($types, ParameterType::STRING, ParameterType::STRING);
        }
        if (null !== $query->enabled) {
            $where[] = 'u.enabled = ?';
            $parameters[] = (int) $query->enabled;
            $types[] = ParameterType::INTEGER;
        }
        if (null !== $query->page->cursor) {
            $query->page->cursor->assertContext(PageCursorKind::Resource, $query->context());
            $where[] = '(u.username > ? OR (u.username = ? AND u.id > ?))';
            array_push($parameters, $query->page->cursor->first, $query->page->cursor->first, (new ReadModelIdentifier($query->page->cursor->second))->binary());
            array_push($types, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY);
        }
        $parameters[] = $query->page->limit + 1;
        $types[] = ParameterType::INTEGER;
        $sql = 'SELECT u.id,u.username,u.display_name,u.enabled,u.revision,u.created_at,u.updated_at,u.disabled_at,u.last_login_at,'
            ." GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ',') roles FROM users u"
            .' LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id'
            .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
            .' GROUP BY u.id,u.username,u.display_name,u.enabled,u.revision,u.created_at,u.updated_at,u.disabled_at,u.last_login_at'
            .' ORDER BY u.username,u.id LIMIT ?';
        $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);
        $hasMore = count($rows) > $query->page->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(fn (array $row): AdministrationUser => $this->user($row), $rows);
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $cursor = $hasMore && is_array($last) ? PageCursor::resource($query->context(), $this->text($last['username']), $this->uuid($last['id'])) : null;
        return new AdministrationPage($query->page, $items, $cursor);
    }

    public function roles(PageRequest $page): AdministrationPage
    {
        $context = PageCursor::context('admin-roles-v1');
        $parameters = [];
        $types = [];
        $where = '';
        if (null !== $page->cursor) {
            $page->cursor->assertContext(PageCursorKind::Resource, $context);
            $where = ' WHERE (r.role_name > ? OR (r.role_name = ? AND r.id > ?))';
            $parameters = [$page->cursor->first, $page->cursor->first, (new ReadModelIdentifier($page->cursor->second))->binary()];
            $types = [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY];
        }
        $parameters[] = $page->limit + 1;
        $types[] = ParameterType::INTEGER;
        $rows = $this->connection->fetchAllAssociative('SELECT r.id,r.role_name,r.display_name,GROUP_CONCAT(p.permission_name ORDER BY p.permission_name SEPARATOR \',\') permissions FROM roles r LEFT JOIN role_permissions rp ON rp.role_id=r.id LEFT JOIN permissions p ON p.id=rp.permission_id'.$where.' GROUP BY r.id,r.role_name,r.display_name ORDER BY r.role_name,r.id LIMIT ?', $parameters, $types);
        $hasMore = count($rows) > $page->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(fn (array $row): AdministrationRole => new AdministrationRole($this->uuid($row['id']), $this->text($row['role_name']), $this->text($row['display_name']), $this->csv($row['permissions'])), $rows);
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $cursor = $hasMore && is_array($last) ? PageCursor::resource($context, $this->text($last['role_name']), $this->uuid($last['id'])) : null;
        return new AdministrationPage($page, $items, $cursor);
    }

    public function audit(AuditListQuery $query): AdministrationPage
    {
        $where = [];
        $parameters = [];
        $types = [];
        if (null !== $query->actorUserId) {
            $where[] = 'actor_user_id = ?';
            $parameters[] = (new ReadModelIdentifier($query->actorUserId))->binary();
            $types[] = ParameterType::BINARY;
        }
        if (null !== $query->eventType) {
            $where[] = 'event_type = ?';
            $parameters[] = $query->eventType->value;
            $types[] = ParameterType::STRING;
        }
        if (null !== $query->outcome) {
            $where[] = 'outcome = ?';
            $parameters[] = $query->outcome->value;
            $types[] = ParameterType::STRING;
        }
        if (null !== $query->page->cursor) {
            $query->page->cursor->assertContext(PageCursorKind::Resource, $query->context());
            $where[] = '(occurred_at < ? OR (occurred_at = ? AND id < ?))';
            $date = $this->fromApiDate($query->page->cursor->first);
            array_push($parameters, $date, $date, (new ReadModelIdentifier($query->page->cursor->second))->binary());
            array_push($types, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY);
        }
        $parameters[] = $query->page->limit + 1;
        $types[] = ParameterType::INTEGER;
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM audit_events'.([] === $where ? '' : ' WHERE '.implode(' AND ', $where)).' ORDER BY occurred_at DESC,id DESC LIMIT ?', $parameters, $types);
        $hasMore = count($rows) > $query->page->limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(fn (array $row): AdministrationAuditEvent => $this->auditView($row), $rows);
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $cursor = $hasMore && is_array($last) ? PageCursor::resource($query->context(), $this->apiDate($last['occurred_at']), $this->uuid($last['id'])) : null;
        return new AdministrationPage($query->page, $items, $cursor);
    }

    public function auditEvent(string $id): ?AdministrationAuditEvent
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM audit_events WHERE id=?', [(new ReadModelIdentifier($id))->binary()], [ParameterType::BINARY]);
        return false === $row ? null : $this->auditView($row);
    }

    private function createUser(SecurityCommand $command, AuthenticatedPrincipal $principal): SecurityCommandResult
    {
        if (0 !== $command->expectedRevision) {
            return new SecurityCommandResult(SecurityCommandStatus::Conflict, 0);
        }
        $now = $this->now();
        $this->connection->insert('users', [
            'id' => $command->subjectId,
            'username' => (new NormalizedUsername($this->payloadString($command, 'username')))->value,
            'display_name' => (new UserDisplayName($this->payloadString($command, 'displayName')))->value,
            'password_hash' => $command->passwordHash?->encoded() ?? throw new RuntimeException('The create password is missing.'),
            'enabled' => 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
            'disabled_at' => null, 'last_login_at' => null,
        ], ['id' => ParameterType::BINARY]);
        $this->assignRoles($command, $principal, $now);
        return new SecurityCommandResult(SecurityCommandStatus::Applied, 1);
    }

    private function updateUser(SecurityCommand $command): SecurityCommandResult
    {
        $row = $this->lockUser($command->subjectId);
        if (null === $row) {
            return SecurityCommandResult::blocked('user_missing');
        }
        $revision = $this->integer($row['revision']);
        if ($revision !== $command->expectedRevision) {
            return new SecurityCommandResult(SecurityCommandStatus::Conflict, $revision);
        }
        $data = ['display_name' => (new UserDisplayName($this->payloadString($command, 'displayName')))->value, 'revision' => $revision + 1, 'updated_at' => $this->now()];
        if (null !== $command->passwordHash) {
            $data['password_hash'] = $command->passwordHash->encoded();
        }
        $this->connection->update('users', $data, ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        if (null !== $command->passwordHash) {
            $this->connection->executeStatement('UPDATE web_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL', [$this->now(), $command->subjectId], [ParameterType::STRING, ParameterType::BINARY]);
        }
        return new SecurityCommandResult(SecurityCommandStatus::Applied, $revision + 1);
    }

    private function disableUser(SecurityCommand $command, AuthenticatedPrincipal $principal): SecurityCommandResult
    {
        $adminRole = $this->lockAdminRole();
        $row = $this->lockUser($command->subjectId);
        if (null === $row) {
            return SecurityCommandResult::blocked('user_missing');
        }
        $revision = $this->integer($row['revision']);
        if ($revision !== $command->expectedRevision) {
            return new SecurityCommandResult(SecurityCommandStatus::Conflict, $revision);
        }
        if (0 === $this->integer($row['enabled'])) {
            return SecurityCommandResult::blocked('user_disabled');
        }
        $targetIsAdmin = $this->hasRole($command->subjectId, $adminRole);
        try {
            $this->lastAdministrator->assertNotSelfLockout(hash_equals($principal->userId->binary(), $command->subjectId), true);
            $this->lastAdministrator->assertCanRemove($targetIsAdmin, $this->enabledAdminCount($adminRole));
        } catch (LastAdministratorViolation $exception) {
            return SecurityCommandResult::blocked(str_contains($exception->getMessage(), 'own') ? 'self_lockout' : 'last_active_admin');
        }
        $now = $this->now();
        $this->connection->update('users', ['enabled' => 0, 'revision' => $revision + 1, 'updated_at' => $now, 'disabled_at' => $now], ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        $this->connection->executeStatement('UPDATE web_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL', [$now, $command->subjectId], [ParameterType::STRING, ParameterType::BINARY]);
        return new SecurityCommandResult(SecurityCommandStatus::Applied, $revision + 1);
    }

    private function replaceRoles(SecurityCommand $command, AuthenticatedPrincipal $principal): SecurityCommandResult
    {
        $adminRole = $this->lockAdminRole();
        $row = $this->lockUser($command->subjectId);
        if (null === $row) {
            return SecurityCommandResult::blocked('user_missing');
        }
        $revision = $this->integer($row['revision']);
        if ($revision !== $command->expectedRevision) {
            return new SecurityCommandResult(SecurityCommandStatus::Conflict, $revision);
        }
        if (0 === $this->integer($row['enabled'])) {
            return SecurityCommandResult::blocked('user_disabled');
        }
        $roles = $this->payloadRoles($command);
        $currentlyAdmin = $this->hasRole($command->subjectId, $adminRole);
        $willBeAdmin = in_array(Role::Admin, $roles, true);
        try {
            $this->lastAdministrator->assertNotSelfLockout(hash_equals($principal->userId->binary(), $command->subjectId), $currentlyAdmin && !$willBeAdmin);
            $this->lastAdministrator->assertCanRemove($currentlyAdmin && !$willBeAdmin, $this->enabledAdminCount($adminRole));
        } catch (LastAdministratorViolation $exception) {
            return SecurityCommandResult::blocked(str_contains($exception->getMessage(), 'own') ? 'self_lockout' : 'last_active_admin');
        }
        $this->connection->delete('user_roles', ['user_id' => $command->subjectId], ['user_id' => ParameterType::BINARY]);
        $this->assignRoles($command, $principal, $this->now());
        $this->connection->update('users', ['revision' => $revision + 1, 'updated_at' => $this->now()], ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        return new SecurityCommandResult(SecurityCommandStatus::Applied, $revision + 1);
    }

    private function assignRoles(SecurityCommand $command, AuthenticatedPrincipal $principal, string $now): void
    {
        $roles = array_map(static fn (Role $role): string => $role->value, $this->payloadRoles($command));
        $rows = $this->connection->fetchAllAssociative('SELECT id,role_name FROM roles WHERE role_name IN (?)', [$roles], [ArrayParameterType::STRING]);
        if (count($rows) !== count($roles)) {
            throw new RuntimeException('A configured role is missing.');
        }
        foreach ($rows as $row) {
            $this->connection->insert('user_roles', ['user_id' => $command->subjectId, 'role_id' => $this->binary($row['id']), 'assigned_at' => $now, 'assigned_by_user_id' => $principal->userId->binary()], ['user_id' => ParameterType::BINARY, 'role_id' => ParameterType::BINARY, 'assigned_by_user_id' => ParameterType::BINARY]);
        }
    }

    /** @return list<Role> */
    private function payloadRoles(SecurityCommand $command): array
    {
        $values = $command->payload['roles'] ?? null;
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException('The command roles are invalid.');
        }
        return array_map(static fn (mixed $role): Role => is_string($role) ? Role::from($role) : throw new RuntimeException('The command role is invalid.'), $values);
    }

    /** @return array<string, mixed>|null */
    private function lockUser(string $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id,enabled,revision FROM users WHERE id=? FOR UPDATE', [$id], [ParameterType::BINARY]);
        return false === $row ? null : $row;
    }

    private function lockAdminRole(): string
    {
        $id = $this->connection->fetchOne("SELECT id FROM roles WHERE role_name='admin' FOR UPDATE");
        return $this->binary($id);
    }

    private function hasRole(string $userId, string $roleId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM user_roles WHERE user_id=? AND role_id=?', [$userId, $roleId], [ParameterType::BINARY, ParameterType::BINARY]);
    }

    private function enabledAdminCount(string $adminRole): int
    {
        return $this->integer($this->connection->fetchOne('SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.enabled=1 AND ur.role_id=?', [$adminRole], [ParameterType::BINARY]));
    }

    private function idempotency(SecurityCommand $command, AuthenticatedPrincipal $principal, bool $lock): ?SecurityCommandResult
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM security_command_idempotency WHERE actor_user_id=? AND idempotency_key=?'.($lock ? ' FOR UPDATE' : ''), [$principal->userId->binary(), $command->idempotencyKey], [ParameterType::BINARY, ParameterType::STRING]);
        if (false === $row) {
            return null;
        }
        $passwordMatches = (null === $row['password_replay_hash'] && null === $command->password)
            || (is_string($row['password_replay_hash']) && null !== $command->password
                && $this->passwords->verify($command->password, new PasswordHash($row['password_replay_hash'])));
        if ($this->text($row['command_type']) !== $command->type->value
            || !hash_equals($this->binary($row['subject_user_id']), $command->subjectId)
            || !hash_equals($this->fixedBytes($row['payload_hash'], 32), $command->payloadHash()) || !$passwordMatches) {
            return new SecurityCommandResult(SecurityCommandStatus::Conflict, $this->currentRevision($command->subjectId));
        }
        $status = SecurityCommandStatus::from($this->text($row['result_status']));
        if (SecurityCommandStatus::Applied === $status) {
            $status = SecurityCommandStatus::Replayed;
        }
        return new SecurityCommandResult($status, null === $row['result_revision'] ? null : $this->integer($row['result_revision']), null === $row['blocker_code'] ? null : $this->text($row['blocker_code']));
    }

    private function persistIdempotency(SecurityCommand $command, AuthenticatedPrincipal $principal, SecurityCommandResult $result): void
    {
        $storedStatus = SecurityCommandStatus::Replayed === $result->status ? SecurityCommandStatus::Applied : $result->status;
        $this->connection->insert('security_command_idempotency', [
            'actor_user_id' => $principal->userId->binary(), 'idempotency_key' => $command->idempotencyKey,
            'command_type' => $command->type->value, 'subject_user_id' => $command->subjectId,
            'payload_hash' => $command->payloadHash(), 'password_replay_hash' => $command->passwordHash?->encoded(),
            'result_status' => $storedStatus->value, 'result_revision' => $result->revision,
            'blocker_code' => $result->blocker, 'created_at' => $this->now(),
        ], ['actor_user_id' => ParameterType::BINARY, 'subject_user_id' => ParameterType::BINARY, 'payload_hash' => ParameterType::BINARY]);
    }

    private function appendAudit(SecurityCommand $command, AuthenticatedPrincipal $principal, SecurityCommandResult $result): void
    {
        $type = $command->type->auditType();
        if (SecurityCommandType::UserRolesReplace === $command->type && !in_array(Role::Admin, $this->payloadRoles($command), true)) {
            $type = AuditEventType::RoleRemoved;
        }
        $this->connection->insert('audit_events', [
            'id' => $this->ids->generate(), 'occurred_at' => $this->now(), 'actor_user_id' => $principal->userId->binary(),
            'actor_session_id' => $principal->sessionId, 'event_type' => $type->value,
            'outcome' => SecurityCommandStatus::Applied === $result->status ? 'succeeded' : 'denied',
            'subject_type' => 'user', 'subject_id' => $command->subjectId,
            'reason_code' => $result->blocker, 'correlation_id' => $command->correlationId,
        ], ['id' => ParameterType::BINARY, 'actor_user_id' => ParameterType::BINARY, 'actor_session_id' => ParameterType::BINARY, 'subject_id' => ParameterType::BINARY, 'correlation_id' => ParameterType::BINARY]);
    }

    private function currentRevision(string $id): int
    {
        $revision = $this->connection->fetchOne('SELECT revision FROM users WHERE id=?', [$id], [ParameterType::BINARY]);
        return false === $revision ? 0 : $this->integer($revision);
    }

    /** @param array<string, mixed> $row */
    private function user(array $row): AdministrationUser
    {
        return new AdministrationUser($this->uuid($row['id']), $this->text($row['username']), $this->text($row['display_name']), 1 === $this->integer($row['enabled']), $this->integer($row['revision']), $this->csv($row['roles']), $this->apiDate($row['created_at']), $this->apiDate($row['updated_at']), $this->nullableDate($row['disabled_at']), $this->nullableDate($row['last_login_at']));
    }

    /** @param array<string, mixed> $row */
    private function auditView(array $row): AdministrationAuditEvent
    {
        return new AdministrationAuditEvent($this->uuid($row['id']), $this->apiDate($row['occurred_at']), $this->nullableUuid($row['actor_user_id']), $this->nullableUuid($row['actor_session_id']), AuditEventType::from($this->text($row['event_type'])), AuditOutcome::from($this->text($row['outcome'])), null === $row['subject_type'] ? null : $this->text($row['subject_type']), $this->nullableUuid($row['subject_id']), null === $row['reason_code'] ? null : $this->text($row['reason_code']), $this->uuid($row['correlation_id']));
    }

    private function payloadString(SecurityCommand $command, string $key): string { $value = $command->payload[$key] ?? null; if (!is_string($value)) throw new RuntimeException('A command text is invalid.'); return $value; }
    private function binary(mixed $value): string { if (!is_string($value) || 16 !== strlen($value)) throw new RuntimeException('Persisted binary data is invalid.'); return $value; }
    private function fixedBytes(mixed $value, int $length): string { if (!is_string($value) || strlen($value) !== $length) throw new RuntimeException('Persisted binary data is invalid.'); return $value; }
    private function text(mixed $value): string { if (!is_string($value)) throw new RuntimeException('Persisted text is invalid.'); return $value; }
    private function integer(mixed $value): int { if (is_int($value)) return $value; if (!is_string($value) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) || strlen($value) > strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) throw new RuntimeException('Persisted integer is invalid.'); return (int) $value; }
    private function uuid(mixed $value): string { $hex = bin2hex($this->binary($value)); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20); }
    private function nullableUuid(mixed $value): ?string { return null === $value ? null : $this->uuid($value); }
    /** @return list<string> */ private function csv(mixed $value): array { return null === $value || '' === $value ? [] : explode(',', $this->text($value)); }
    private function now(): string { return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(self::DB_DATE); }
    private function apiDate(mixed $value): string { $text = $this->text($value); $date = DateTimeImmutable::createFromFormat('!'.self::DB_DATE, $text, new DateTimeZone('UTC')); if (false === $date) throw new RuntimeException('Persisted timestamp is invalid.'); return $date->format('Y-m-d\TH:i:s.u\Z'); }
    private function fromApiDate(string $value): string { $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC')); if (false === $date || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) throw new RuntimeException('Cursor timestamp is invalid.'); return $date->format(self::DB_DATE); }
    private function nullableDate(mixed $value): ?string { return null === $value ? null : $this->apiDate($value); }
    private function escapeLike(string $value): string { return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value); }
}
