<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Administration\ReadModel\AuditListQuery;
use App\Application\Administration\ReadModel\UserListQuery;
use App\Application\Administration\SecurityCommandBuilder;
use App\Application\Administration\SecurityCommandStatus;
use App\Application\Administration\SecurityCommandType;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\MariaDb\DbalSecurityAdministration;
use App\Infrastructure\Security\NativeArgon2idPasswordHasher;
use DateTimeImmutable;

final class SecurityAdministrationRepositoryTest extends DatabaseTestCase
{
    private const string ACTOR = 'aaaaaaaaaaaaaaaa';
    private const string ADMIN = 'bbbbbbbbbbbbbbbb';
    private const string CREATED = 'cccccccccccccccc';
    private const string NOW = '2026-07-12 12:00:00.000000';
    private DbalSecurityAdministration $repository;
    private SecurityCommandBuilder $builder;
    private AuthenticatedPrincipal $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedUser(self::ACTOR, 'security-actor', 'viewer');
        $this->seedUser(self::ADMIN, 'only-admin', 'admin');
        $passwords = new NativeArgon2idPasswordHasher();
        $ids = new RepositoryIds();
        $clock = new RepositoryClock();
        $this->repository = new DbalSecurityAdministration($this->connection(), $passwords, $clock, $ids);
        $this->builder = new SecurityCommandBuilder($passwords, $ids);
        $sessionId = $this->seedWebSession(self::ACTOR);
        $this->principal = new AuthenticatedPrincipal(new UserId(self::ACTOR), new NormalizedUsername('security-actor'), [Permission::SecurityManage, Permission::AuditRead], $sessionId);
    }

    public function testCreateReplayPasswordConflictAndPasswordFreeReadModelsAreAtomic(): void
    {
        $command = $this->builder->build(SecurityCommandType::UserCreate, self::CREATED, 0, ['username' => 'created.user', 'displayName' => 'Created User', 'roles' => ['viewer']], 'create-user', str_repeat('r', 16), 'very-secret-1');
        self::assertSame(SecurityCommandStatus::Applied, $this->repository->execute($command, $this->principal)->status);
        self::assertSame(SecurityCommandStatus::Replayed, $this->repository->execute($command, $this->principal)->status);
        $changedPassword = $this->builder->build(SecurityCommandType::UserCreate, self::CREATED, 0, ['username' => 'created.user', 'displayName' => 'Created User', 'roles' => ['viewer']], 'create-user', str_repeat('r', 16), 'different-secret');
        self::assertSame(SecurityCommandStatus::Conflict, $this->repository->execute($changedPassword, $this->principal)->status);

        $hash = $this->connection()->fetchOne('SELECT password_hash FROM users WHERE id=?', [self::CREATED]);
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringNotContainsString('very-secret-1', $hash);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM security_command_idempotency WHERE idempotency_key=?', ['create-user']));
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_events WHERE subject_id=?', [self::CREATED]));
        self::assertSame($this->principal->sessionId, $this->connection()->fetchOne('SELECT actor_session_id FROM audit_events WHERE subject_id=?', [self::CREATED]));

        $users = $this->repository->users(new UserListQuery(new PageRequest(2), 'created', true))->toArray();
        self::assertCount(1, $users['items']);
        self::assertArrayNotHasKey('password', $users['items'][0]);
        $roles = $this->repository->roles(new PageRequest(1))->toArray();
        self::assertCount(1, $roles['items']);
        $role = $roles['items'][0];
        $permissions = $role['permissions'] ?? null;
        self::assertIsArray($permissions);
        self::assertContains('security.manage', $permissions);
        $audit = $this->repository->audit(new AuditListQuery(new PageRequest(10), null, null, null))->toArray();
        self::assertNotSame([], $audit['items']);
        $event = $audit['items'][0];
        $eventId = $event['id'] ?? null;
        self::assertIsString($eventId);
        self::assertNotNull($this->repository->auditEvent($eventId));
    }

    public function testPasswordUpdateRevokesSessionsAndRevisionConflictsDoNotMutate(): void
    {
        $this->connection()->insert('web_sessions', [
            'id' => str_repeat('s', 16), 'user_id' => self::ADMIN, 'token_hash' => str_repeat('t', 32),
            'csrf_secret_hash' => str_repeat('c', 32), 'issued_at' => '2026-07-12 11:00:00.000000',
            'last_seen_at' => '2026-07-12 11:00:00.000000', 'idle_expires_at' => '2026-07-12 11:30:00.000000',
            'absolute_expires_at' => '2026-07-12 23:00:00.000000', 'revoked_at' => null,
        ]);
        $stale = $this->builder->build(SecurityCommandType::UserUpdate, self::ADMIN, 99, ['displayName' => 'No Change'], 'stale', null, null);
        self::assertSame(SecurityCommandStatus::Conflict, $this->repository->execute($stale, $this->principal)->status);
        $update = $this->builder->build(SecurityCommandType::UserUpdate, self::ADMIN, 1, ['displayName' => 'Changed'], 'password-update', null, 'replacement-1');
        self::assertSame(SecurityCommandStatus::Applied, $this->repository->execute($update, $this->principal)->status);
        self::assertSame(self::NOW, $this->connection()->fetchOne('SELECT revoked_at FROM web_sessions WHERE user_id=?', [self::ADMIN]));
        self::assertSame('Changed', $this->connection()->fetchOne('SELECT display_name FROM users WHERE id=?', [self::ADMIN]));
    }

    public function testLastAdminAndSelfLockoutRemainBlockedInsideTheTransaction(): void
    {
        $last = $this->builder->build(SecurityCommandType::UserDisable, self::ADMIN, 1, [], 'disable-last', null, null);
        $result = $this->repository->execute($last, $this->principal);
        self::assertSame(SecurityCommandStatus::Blocked, $result->status);
        self::assertSame('last_active_admin', $result->blocker);
        self::assertSame(1, $this->connection()->fetchOne('SELECT enabled FROM users WHERE id=?', [self::ADMIN]));

        $selfPrincipal = new AuthenticatedPrincipal(new UserId(self::ADMIN), new NormalizedUsername('only-admin'), [Permission::SecurityManage]);
        $self = $this->builder->build(SecurityCommandType::UserRolesReplace, self::ADMIN, 1, ['roles' => ['viewer']], 'remove-self-admin', null, null);
        $result = $this->repository->execute($self, $selfPrincipal);
        self::assertSame(SecurityCommandStatus::Blocked, $result->status);
        self::assertSame('self_lockout', $result->blocker);
        self::assertSame('admin', $this->connection()->fetchOne('SELECT r.role_name FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=?', [self::ADMIN]));
    }

    private function seedUser(string $id, string $username, string $role): void
    {
        $this->connection()->insert('users', ['id' => $id, 'username' => $username, 'display_name' => $username, 'password_hash' => '$argon2id$dummy', 'enabled' => 1, 'revision' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $roleId = $this->connection()->fetchOne('SELECT id FROM roles WHERE role_name=?', [$role]);
        self::assertIsString($roleId);
        $this->connection()->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId, 'assigned_at' => self::NOW, 'assigned_by_user_id' => null]);
    }
}

final class RepositoryClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-12T12:00:00.000000Z'); }
}

final class RepositoryIds implements SecurityIdentifierGenerator
{
    private int $counter = 0;
    public function generate(): string { return pack('J', 0).pack('J', ++$this->counter); }
}
