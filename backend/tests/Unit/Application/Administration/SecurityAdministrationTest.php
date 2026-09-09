<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Administration;

use App\Application\Administration\ReadModel\AdministrationAuditEvent;
use App\Application\Administration\ReadModel\AdministrationPage;
use App\Application\Administration\ReadModel\AdministrationRole;
use App\Application\Administration\ReadModel\AdministrationUser;
use App\Application\Administration\ReadModel\AuditListQuery;
use App\Application\Administration\ReadModel\UserListQuery;
use App\Application\Administration\SecurityCommand;
use App\Application\Administration\SecurityCommandBuilder;
use App\Application\Administration\SecurityCommandHandler;
use App\Application\Administration\SecurityCommandRepository;
use App\Application\Administration\SecurityCommandResult;
use App\Application\Administration\SecurityCommandStatus;
use App\Application\Administration\SecurityCommandType;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\Auth\PasswordHasher;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Application\Security\PlaintextSecret;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SecurityAdministrationTest extends TestCase
{
    private SecurityCommandBuilder $builder;
    private AdministrationPasswordHasher $passwords;

    protected function setUp(): void
    {
        $this->passwords = new AdministrationPasswordHasher();
        $this->builder = new SecurityCommandBuilder($this->passwords, new AdministrationIds());
    }

    public function testBuilderProducesWriteOnlyCanonicalCommandsForEveryType(): void
    {
        $create = $this->builder->build(SecurityCommandType::UserCreate, null, 0, ['username' => 'admin.user', 'displayName' => 'Admin', 'roles' => ['admin']], 'create-1', null, 'very-secret-1');
        self::assertSame(str_repeat('i', 16), $create->subjectId);
        self::assertSame(str_repeat('j', 16), $create->correlationId);
        self::assertInstanceOf(PlaintextSecret::class, $create->password);
        self::assertInstanceOf(PasswordHash::class, $create->passwordHash);
        self::assertSame(['value' => '[REDACTED]'], $create->password->__debugInfo());
        self::assertSame(['value' => '[REDACTED]'], $create->passwordHash->__debugInfo());
        self::assertStringNotContainsString('secret', bin2hex($create->payloadHash()));

        $a = new SecurityCommand(SecurityCommandType::UserUpdate, str_repeat('u', 16), 1, ['displayName' => 'User', 'nested' => ['b' => 2, 'a' => 1]], 'update-1', str_repeat('c', 16));
        $b = new SecurityCommand(SecurityCommandType::UserUpdate, str_repeat('u', 16), 1, ['nested' => ['a' => 1, 'b' => 2], 'displayName' => 'User'], 'update-1', str_repeat('c', 16));
        self::assertSame($a->payloadHash(), $b->payloadHash());

        self::assertNull($this->builder->build(SecurityCommandType::UserUpdate, str_repeat('u', 16), 1, ['displayName' => 'User'], 'update-2', str_repeat('c', 16), null)->password);
        self::assertSame([], $this->builder->build(SecurityCommandType::UserDisable, str_repeat('u', 16), 1, [], 'disable-1', str_repeat('c', 16), null)->payload);
        self::assertSame(['viewer'], $this->builder->build(SecurityCommandType::UserRolesReplace, str_repeat('u', 16), 1, ['roles' => ['viewer']], 'roles-1', str_repeat('c', 16), null)->payload['roles']);
        self::assertSame([
            'user.create' => AuditEventType::UserCreated,
            'user.update' => AuditEventType::UserUpdated,
            'user.disable' => AuditEventType::UserDisabled,
            'user.roles.replace' => AuditEventType::RoleAssigned,
        ], array_combine(
            array_column(SecurityCommandType::cases(), 'value'),
            array_map(static fn (SecurityCommandType $type): AuditEventType => $type->auditType(), SecurityCommandType::cases()),
        ));
    }

    public function testInvalidCommandsPayloadsAndResultsFailClosed(): void
    {
        $invalid = [
            fn () => new SecurityCommand(SecurityCommandType::UserDisable, 'bad', 1, [], 'key', str_repeat('c', 16)),
            fn () => new SecurityCommand(SecurityCommandType::UserDisable, str_repeat('u', 16), -1, [], 'key', str_repeat('c', 16)),
            fn () => new SecurityCommand(SecurityCommandType::UserDisable, str_repeat('u', 16), 1, [], '.bad', str_repeat('c', 16)),
            fn () => new SecurityCommand(SecurityCommandType::UserDisable, str_repeat('u', 16), 1, [], 'key', 'bad'),
            fn () => new SecurityCommand(SecurityCommandType::UserUpdate, str_repeat('u', 16), 1, [], 'key', str_repeat('c', 16), PlaintextSecret::fromString('secret')),
            fn () => new SecurityCommand(SecurityCommandType::UserUpdate, str_repeat('u', 16), 1, [], 'key', str_repeat('c', 16), null, new PasswordHash('$argon2id$hash')),
            fn () => $this->builder->build(SecurityCommandType::UserCreate, null, 0, ['username' => 'xx', 'displayName' => 'X', 'roles' => ['admin']], 'key', null, 'very-secret-1'),
            fn () => $this->builder->build(SecurityCommandType::UserCreate, null, 0, ['username' => 'valid', 'displayName' => 'X', 'roles' => ['admin']], 'key', null, null),
            fn () => $this->builder->build(SecurityCommandType::UserCreate, null, 0, ['username' => 'valid', 'displayName' => 'X', 'roles' => []], 'key', null, 'very-secret-1'),
            fn () => $this->builder->build(SecurityCommandType::UserRolesReplace, str_repeat('u', 16), 1, ['roles' => ['admin', 'admin']], 'key', null, null),
            fn () => $this->builder->build(SecurityCommandType::UserRolesReplace, str_repeat('u', 16), 1, ['roles' => ['root']], 'key', null, null),
            fn () => $this->builder->build(SecurityCommandType::UserDisable, str_repeat('u', 16), 1, ['extra' => true], 'key', null, null),
            fn () => new SecurityCommandResult(SecurityCommandStatus::Applied),
            fn () => new SecurityCommandResult(SecurityCommandStatus::Blocked, 1, 'blocked'),
            fn () => SecurityCommandResult::blocked('INVALID'),
        ];
        foreach ($invalid as $operation) {
            try { $operation(); self::fail('Invalid security command accepted.'); } catch (InvalidArgumentException) { self::addToAssertionCount(1); }
        }
        self::assertSame('blocked', SecurityCommandResult::blocked('blocked')->blocker);
        foreach ([SecurityCommandStatus::Applied, SecurityCommandStatus::Replayed, SecurityCommandStatus::Conflict] as $status) {
            self::assertSame(1, (new SecurityCommandResult($status, 1))->revision);
        }
        self::assertSame('permission_denied', (new SecurityCommandResult(SecurityCommandStatus::Denied, null, 'permission_denied'))->blocker);
    }

    public function testCanonicalPayloadAndEveryBuilderBoundaryAreClosed(): void
    {
        $listA = new SecurityCommand(SecurityCommandType::UserUpdate, str_repeat('u', 16), 1, ['displayName' => 'User', 'list' => [['z' => 1, 'a' => 2], 'x']], 'list-1', str_repeat('c', 16));
        $listB = new SecurityCommand(SecurityCommandType::UserUpdate, str_repeat('u', 16), 1, ['list' => [['a' => 2, 'z' => 1], 'x'], 'displayName' => 'User'], 'list-1', str_repeat('c', 16));
        self::assertSame($listA->payloadHash(), $listB->payloadHash());

        $invalid = [
            fn () => (new SecurityCommand(SecurityCommandType::UserUpdate, str_repeat('u', 16), 1, ['invalid' => NAN], 'nan', str_repeat('c', 16)))->payloadHash(),
            fn () => $this->builder->build(SecurityCommandType::UserCreate, null, 0, ['username' => 'valid', 'displayName' => 'User', 'roles' => [1]], 'role-type', null, 'very-secret-1'),
            fn () => $this->builder->build(SecurityCommandType::UserUpdate, str_repeat('u', 16), 1, ['displayName' => 1], 'display-type', null, null),
            fn () => new SecurityCommandResult(SecurityCommandStatus::Denied, null, null),
            fn () => new SecurityCommandResult(SecurityCommandStatus::Denied, 1, 'permission_denied'),
            fn () => new SecurityCommandResult(SecurityCommandStatus::Conflict, -1),
            fn () => new SecurityCommandResult(SecurityCommandStatus::Applied, 1, 'unexpected'),
            fn () => new SecurityCommandResult(SecurityCommandStatus::Replayed, null),
        ];
        foreach ($invalid as $operation) {
            try { $operation(); self::fail('Invalid boundary accepted.'); } catch (InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }

    public function testHandlerRequiresDedicatedPermissionAndRecordsDenial(): void
    {
        $repository = new AdministrationRepositoryFake();
        $handler = new SecurityCommandHandler(new PermissionAuthorizer(), $repository);
        $command = new SecurityCommand(SecurityCommandType::UserDisable, str_repeat('u', 16), 1, [], 'key', str_repeat('c', 16));
        $denied = $handler->handle($command, $this->principal([]));
        self::assertSame(SecurityCommandStatus::Denied, $denied->status);
        self::assertSame(1, $repository->records);
        self::assertSame(SecurityCommandStatus::Applied, $handler->handle($command, $this->principal([Permission::SecurityManage]))->status);
        self::assertSame(1, $repository->executions);
    }

    public function testReadDtosQueriesAndPagesExposeOnlyClosedFields(): void
    {
        $user = new AdministrationUser('id', 'user', 'User', true, 1, ['admin'], 'created', 'updated', null, 'last-login');
        self::assertSame([
            'id' => 'id',
            'username' => 'user',
            'displayName' => 'User',
            'enabled' => true,
            'revision' => 1,
            'roles' => ['admin'],
            'createdAt' => 'created',
            'updatedAt' => 'updated',
            'disabledAt' => null,
            'lastLoginAt' => 'last-login',
        ], $user->toArray());
        $role = new AdministrationRole('id', 'admin', 'Administrator', ['security.manage']);
        self::assertSame([
            'id' => 'id',
            'name' => 'admin',
            'displayName' => 'Administrator',
            'permissions' => ['security.manage'],
        ], $role->toArray());
        $audit = new AdministrationAuditEvent('event-id', 'at', 'actor', 'session', AuditEventType::UserUpdated, AuditOutcome::Succeeded, 'user', 'subject', 'reason', 'correlation');
        self::assertSame([
            'id' => 'event-id',
            'occurredAt' => 'at',
            'actorUserId' => 'actor',
            'actorSessionId' => 'session',
            'eventType' => 'user_updated',
            'outcome' => 'succeeded',
            'subjectType' => 'user',
            'subjectId' => 'subject',
            'reasonCode' => 'reason',
            'correlationId' => 'correlation',
        ], $audit->toArray());
        $page = new AdministrationPage(new PageRequest(2), [$user, $role], null);
        self::assertSame([
            'items' => [$user->toArray(), $role->toArray()],
            'page' => ['limit' => 2, 'count' => 2, 'hasMore' => false, 'nextCursor' => null],
        ], $page->toArray());

        $cursor = PageCursor::resource(
            PageCursor::context('admin-page-contract'),
            '2026-07-13T00:00:00.000000Z',
            '00112233-4455-6677-8899-aabbccddeeff',
        );
        self::assertSame([
            'items' => [$role->toArray()],
            'page' => ['limit' => 2, 'count' => 1, 'hasMore' => true, 'nextCursor' => $cursor->opaque()],
        ], (new AdministrationPage(new PageRequest(2), [$role], $cursor))->toArray());
        self::assertNotSame((new UserListQuery(new PageRequest(), 'a', true))->context(), (new UserListQuery(new PageRequest(), 'a', false))->context());
        self::assertNotSame((new AuditListQuery(new PageRequest(), null, AuditEventType::UserCreated, null))->context(), (new AuditListQuery(new PageRequest(), null, AuditEventType::UserDisabled, null))->context());
        $actor = '00112233-4455-6677-8899-aabbccddeeff';
        self::assertNotSame(
            (new AuditListQuery(new PageRequest(), $actor, AuditEventType::UserCreated, AuditOutcome::Succeeded))->context(),
            (new AuditListQuery(new PageRequest(), null, null, AuditOutcome::Denied))->context(),
        );
        self::assertNotSame(
            (new UserListQuery(new PageRequest(), null, null))->context(),
            (new UserListQuery(new PageRequest(), 'a', true))->context(),
        );
        foreach ([fn () => new UserListQuery(new PageRequest(), ' '), fn () => new AdministrationPage(new PageRequest(1), [$user, $role], null), fn () => new AdministrationPage(new PageRequest(), [], PageCursor::resource(PageCursor::context('x'), 'x', '00112233-4455-6677-8899-aabbccddeeff'))] as $invalid) {
            try { $invalid(); self::fail('Invalid administration projection accepted.'); } catch (InvalidArgumentException) { self::addToAssertionCount(1); }
        }
        foreach ([fn () => new UserListQuery(new PageRequest(), ''), fn () => new UserListQuery(new PageRequest(), str_repeat('x', 191)), fn () => new AuditListQuery(new PageRequest(), 'invalid')] as $invalid) {
            try { $invalid(); self::fail('Invalid administration query accepted.'); } catch (InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }

    /** @param list<Permission> $permissions */
    private function principal(array $permissions): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(new UserId(str_repeat('a', 16)), new NormalizedUsername('admin'), $permissions);
    }
}

final class AdministrationPasswordHasher implements PasswordHasher
{
    public function hash(PlaintextSecret $password): PasswordHash { return new PasswordHash('$argon2id$'.hash('sha256', $password->consume(static fn (string $value): string => $value))); }
    public function verify(PlaintextSecret $password, PasswordHash $hash): bool { return hash_equals($this->hash($password)->encoded(), $hash->encoded()); }
    public function needsRehash(PasswordHash $hash): bool { return false; }
}

final class AdministrationIds implements SecurityIdentifierGenerator
{
    private int $offset = 0;
    public function generate(): string { return str_repeat(chr(105 + ($this->offset++ % 10)), 16); }
}

final class AdministrationRepositoryFake implements SecurityCommandRepository
{
    public int $executions = 0;
    public int $records = 0;
    public function execute(SecurityCommand $command, AuthenticatedPrincipal $principal): SecurityCommandResult { ++$this->executions; return new SecurityCommandResult(SecurityCommandStatus::Applied, 2); }
    public function record(SecurityCommand $command, AuthenticatedPrincipal $principal, SecurityCommandResult $result): SecurityCommandResult { ++$this->records; return $result; }
}
