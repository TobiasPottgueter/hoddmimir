<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Security\Auth;

use App\Application\Security\Audit\AuditEventStore;
use App\Application\Security\Audit\SecurityAuditRecorder;
use App\Application\Security\Auth\AdminBootstrapConflict;
use App\Application\Security\Auth\CreateFirstAdmin;
use App\Application\Security\Auth\ExistingAdmin;
use App\Application\Security\Auth\FirstAdminStore;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Application\Security\Auth\SecurityTransaction;
use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\PlaintextSecret;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\UserDisplayName;
use App\Domain\Security\UserId;
use App\Infrastructure\Security\NativeArgon2idPasswordHasher;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CreateFirstAdminTest extends TestCase
{
    public function testCreatesOnlyUserInEmptyStoreAndAuditsIt(): void
    {
        $passwords = new NativeArgon2idPasswordHasher();
        $store = $this->createMock(FirstAdminStore::class);
        $store->method('findAdmin')->willReturn(null);
        $store->method('countUsers')->willReturn(0);
        $store->expects(self::once())->method('createAdmin')->with(
            self::isInstanceOf(UserId::class),
            self::callback(static fn (NormalizedUsername $username): bool => 'admin' === $username->value),
            self::callback(static fn (UserDisplayName $display): bool => 'Administrator' === $display->value),
            self::isInstanceOf(PasswordHash::class),
            self::isInstanceOf(DateTimeImmutable::class),
        );
        self::assertTrue($this->creator($store, $passwords)->create('admin', 'Administrator', 'twelve-chars', false));
    }

    public function testRefusesBootstrapWhenAnyOtherUserExists(): void
    {
        $passwords = new NativeArgon2idPasswordHasher();
        $store = $this->createStub(FirstAdminStore::class);
        $store->method('findAdmin')->willReturn(null);
        $store->method('countUsers')->willReturn(1);
        $this->expectException(AdminBootstrapConflict::class);
        $this->creator($store, $passwords)->create('admin', 'Administrator', 'twelve-chars', false);
    }

    public function testIdempotencyRequiresExplicitFlagAndExactDisplayNameAndPassword(): void
    {
        $passwords = new NativeArgon2idPasswordHasher();
        $existing = new ExistingAdmin(
            new UserId(str_repeat('u', 16)),
            new NormalizedUsername('admin'),
            new UserDisplayName('Administrator'),
            $passwords->hash(PlaintextSecret::fromString('exact-password')),
        );
        $store = $this->createMock(FirstAdminStore::class);
        $store->method('findAdmin')->willReturn($existing);
        $store->expects(self::never())->method('createAdmin');
        $creator = $this->creator($store, $passwords);

        self::assertFalse($creator->create('admin', 'Administrator', 'exact-password', true));
        foreach ([
            ['admin', 'Administrator', 'exact-password', false],
            ['admin', 'Different', 'exact-password', true],
            ['admin', 'Administrator', 'wrong-password', true],
        ] as $arguments) {
            try {
                $creator->create(...$arguments);
                self::fail('Non-exact administrator state was treated as idempotent.');
            } catch (AdminBootstrapConflict) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function creator(FirstAdminStore $store, NativeArgon2idPasswordHasher $passwords): CreateFirstAdmin
    {
        $transaction = $this->createStub(SecurityTransaction::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $ids = $this->createStub(SecurityIdentifierGenerator::class);
        $ids->method('generate')->willReturn(str_repeat('i', 16));
        $auditStore = $this->createStub(AuditEventStore::class);
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-12T10:00:00Z'));

        return new CreateFirstAdmin($transaction, $store, $passwords, $ids, new SecurityAuditRecorder($auditStore, $ids, $clock), $clock);
    }
}
