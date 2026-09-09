<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Operations;

use App\Application\Backup\Operations\BackupOperationCommand;
use App\Application\Backup\Operations\BackupOperationCommandHandler;
use App\Application\Backup\Operations\BackupOperationCommandRepository;
use App\Application\Backup\Operations\BackupOperationCommandResult;
use App\Application\Backup\Operations\BackupOperationCommandStatus;
use App\Application\Backup\Operations\BackupOperationCommandType;
use App\Application\Backup\Operations\BackupRequestState;
use App\Application\Backup\Operations\OperationsPage;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Security\Audit\AuditEventStore;
use App\Application\Security\Audit\SecurityAuditEvent;
use App\Application\Security\Audit\SecurityAuditRecorder;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackupOperationsContractsTest extends TestCase
{
    public function testRequestStatesClassifyOnlyDurableTerminalStates(): void
    {
        self::assertTrue(BackupRequestState::Succeeded->terminal());
        self::assertTrue(BackupRequestState::Failed->terminal());
        self::assertTrue(BackupRequestState::Cancelled->terminal());
        self::assertTrue(BackupRequestState::Unknown->terminal());
        foreach ([
            BackupRequestState::Pending,
            BackupRequestState::RetryWait,
            BackupRequestState::Leased,
            BackupRequestState::Starting,
            BackupRequestState::Running,
            BackupRequestState::ReconcileRequired,
        ] as $nonTerminal) {
            self::assertFalse($nonTerminal->terminal());
        }
    }

    public function testPageSerializesStableCursorMetadata(): void
    {
        $page = (new OperationsPage(new PageRequest(20), [['id' => 'one']], null))->toArray();
        self::assertSame(['limit'=>20,'count'=>1,'hasMore'=>false,'nextCursor'=>null], $page['page']);
    }

    public function testCommandHashIncludesRevisionAndSubject(): void
    {
        $one = new BackupOperationCommand(BackupOperationCommandType::ManualRequest, str_repeat('a',16), str_repeat('p',16), str_repeat('g',16), 1, 'operation-1', str_repeat('c',16));
        $two = new BackupOperationCommand(BackupOperationCommandType::ManualRequest, str_repeat('a',16), str_repeat('p',16), str_repeat('g',16), 2, 'operation-1', str_repeat('c',16));
        self::assertNotSame($one->payloadHash, $two->payloadHash);
        self::assertSame(32, strlen((new BackupOperationCommand(
            BackupOperationCommandType::ManualRequest,
            str_repeat('a', 16),
            str_repeat('p', 16),
            str_repeat('g', 16),
            0,
            str_repeat('x', 128),
            str_repeat('c', 16),
        ))->payloadHash));
    }

    public function testCommandRejectsUnsafeIdempotencyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupOperationCommand(BackupOperationCommandType::CancelRequest, str_repeat('a',16), str_repeat("\0",16), str_repeat("\0",16), 1, '../bad', str_repeat('c',16));
    }

    public function testCommandRejectsEveryIndependentIdentifierAndRevisionFailure(): void
    {
        $id = str_repeat('a', 16);
        $policy = str_repeat('p', 16);
        $guest = str_repeat('g', 16);
        $correlation = str_repeat('c', 16);
        $invalidCommands = [
            fn () => new BackupOperationCommand(BackupOperationCommandType::ManualRequest, 'bad', $policy, $guest, 1, 'operation-1', $correlation),
            fn () => new BackupOperationCommand(BackupOperationCommandType::ManualRequest, $id, 'bad', $guest, 1, 'operation-1', $correlation),
            fn () => new BackupOperationCommand(BackupOperationCommandType::ManualRequest, $id, $policy, 'bad', 1, 'operation-1', $correlation),
            fn () => new BackupOperationCommand(BackupOperationCommandType::ManualRequest, $id, $policy, $guest, -1, 'operation-1', $correlation),
            fn () => new BackupOperationCommand(BackupOperationCommandType::ManualRequest, $id, $policy, $guest, 1, '', $correlation),
            fn () => new BackupOperationCommand(BackupOperationCommandType::ManualRequest, $id, $policy, $guest, 1, str_repeat('x', 129), $correlation),
            fn () => new BackupOperationCommand(BackupOperationCommandType::ManualRequest, $id, $policy, $guest, 1, 'operation-1', 'bad'),
        ];
        foreach ($invalidCommands as $index => $invalidCommand) {
            try {
                $invalidCommand();
                self::fail('Expected invalid command field '.$index.' to be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCommandResultRejectsBlockedAndRevisionShapeMismatches(): void
    {
        foreach ([
            [BackupOperationCommandStatus::Blocked, 1, 'blocked'],
            [BackupOperationCommandStatus::Blocked, null, null],
            [BackupOperationCommandStatus::Applied, 1, 'unexpected'],
            [BackupOperationCommandStatus::Applied, null, null],
        ] as [$status, $revision, $blocker]) {
            try {
                new BackupOperationCommandResult($status, $revision, $blocker);
                self::fail('Expected the invalid result shape to be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testHandlerRequiresOperationsPermissionBeforePersistence(): void
    {
        $repository = new OperationRepositoryFake();
        $audit = new OperationAuditStore();
        $handler = new BackupOperationCommandHandler(new PermissionAuthorizer(), $repository, $this->audit($audit));
        $command = new BackupOperationCommand(BackupOperationCommandType::CancelRequest, str_repeat('a',16), str_repeat("\0",16), str_repeat("\0",16), 1, 'cancel-1', str_repeat('c',16));
        $principal = $this->principal([Permission::InventoryRead]);
        $this->expectException(AuthorizationDenied::class);
        try { $handler->handle($command, $principal); } finally {
            self::assertSame(0, $repository->calls);
            self::assertCount(1, $audit->events);
            self::assertSame('permission_denied', $audit->events[0]->reasonCode ?? null);
            self::assertSame(str_repeat('s', 16), $audit->events[0]->actorSessionId ?? null);
        }
    }

    public function testHandlerDelegatesForOperationsManager(): void
    {
        $repository = new OperationRepositoryFake();
        $result = (new BackupOperationCommandHandler(new PermissionAuthorizer(), $repository, $this->audit(new OperationAuditStore())))->handle(
            new BackupOperationCommand(BackupOperationCommandType::CancelRequest, str_repeat('a',16), str_repeat("\0",16), str_repeat("\0",16), 1, 'cancel-1', str_repeat('c',16)),
            $this->principal([Permission::BackupOperationsManage]),
        );
        self::assertSame(BackupOperationCommandStatus::Applied, $result->status);
        self::assertSame(1, $repository->calls);
    }

    /** @param list<Permission> $permissions */
    private function principal(array $permissions): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(new UserId(str_repeat('u',16)), new NormalizedUsername('operator'), $permissions, str_repeat('s', 16));
    }

    private function audit(OperationAuditStore $store): SecurityAuditRecorder
    {
        return new SecurityAuditRecorder(
            $store,
            new OperationAuditIds(),
            new FrozenClock(new DateTimeImmutable('2026-07-13T10:00:00Z')),
        );
    }
}

final class OperationAuditStore implements AuditEventStore
{
    /** @var list<SecurityAuditEvent> */
    public array $events = [];

    public function append(SecurityAuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

final class OperationAuditIds implements SecurityIdentifierGenerator
{
    public function generate(): string
    {
        return str_repeat('i', 16);
    }
}

final class OperationRepositoryFake implements BackupOperationCommandRepository
{
    public int $calls = 0;
    public function execute(BackupOperationCommand $command, AuthenticatedPrincipal $principal): BackupOperationCommandResult
    {
        ++$this->calls;
        return new BackupOperationCommandResult(BackupOperationCommandStatus::Applied, 2);
    }
}
