<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Security\Auth;

use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthenticationIdentity;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Application\Security\Auth\SecurityDigest;
use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;
use App\Application\Security\Audit\SecurityAuditEvent;
use App\Application\Security\PlaintextSecret;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use App\Infrastructure\Security\NativeArgon2idPasswordHasher;
use App\Infrastructure\Security\Sha256OpaqueSecretHasher;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SecurityApplicationTest extends TestCase
{
    public function testArgon2idHashingVerificationRehashAndRedaction(): void
    {
        $hasher = new NativeArgon2idPasswordHasher();
        $plain = PlaintextSecret::fromString('correct horse battery staple');
        $hash = $hasher->hash($plain);
        self::assertStringStartsWith('$argon2id$', $hash->encoded());
        self::assertTrue($hasher->verify($plain, $hash));
        self::assertFalse($hasher->verify(PlaintextSecret::fromString('wrong'), $hash));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertSame(['value' => '[REDACTED]'], $hash->__debugInfo());
        $roundTrip = unserialize(serialize($hash));
        self::assertInstanceOf(PasswordHash::class, $roundTrip);
        self::assertSame($hash->encoded(), $roundTrip->encoded());
    }
    public function testHashAndDigestContractsRejectWrongShapeAndCompareConstantTime(): void
    {
        foreach (['','argon2id','$argon2id$'.str_repeat('a', 246)] as $value) {
            try {
                new PasswordHash($value);
                self::fail();
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        try {
            new SecurityDigest('short');
            self::fail();
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $digest = (new Sha256OpaqueSecretHasher())->digest(PlaintextSecret::fromString(str_repeat('x', 32)));
        self::assertSame(32, strlen($digest->binary()));
        self::assertTrue($digest->equals(new SecurityDigest($digest->binary())));
        self::assertFalse($digest->equals(new SecurityDigest(random_bytes(32))));
    }
    public function testPrincipalDeduplicatesPermissionsAndAuthorizerFailsClosed(): void
    {
        $principal = new AuthenticatedPrincipal(new UserId(random_bytes(16)), new NormalizedUsername('viewer'), [Permission::InventoryRead,Permission::InventoryRead]);
        self::assertCount(1, $principal->permissions);
        self::assertTrue($principal->has(Permission::InventoryRead));
        self::assertFalse($principal->has(Permission::AuditRead));
        self::assertSame([], (new AuthenticatedPrincipal(new UserId(random_bytes(16)), new NormalizedUsername('empty'), []))->permissions);
        $identity = new AuthenticationIdentity($principal->userId, $principal->username, new PasswordHash('$argon2id$test'), $principal->permissions);
        self::assertSame($principal->userId, $identity->userId);
        $multi = new AuthenticatedPrincipal(new UserId(random_bytes(16)), new NormalizedUsername('multi'), [Permission::AuditRead, Permission::InventoryRead]);
        self::assertTrue($multi->has(Permission::AuditRead));
        $authorizer = new PermissionAuthorizer();
        $authorizer->require($principal, Permission::InventoryRead);
        self::addToAssertionCount(1);
        $this->expectException(AuthorizationDenied::class);
        $authorizer->require($principal, Permission::AuditRead);
    }
    public function testPrincipalAndStructuredAuditRejectInvalidRuntimeInputs(): void
    {
        try {
            new AuthenticatedPrincipal(new UserId(random_bytes(16)), new NormalizedUsername('viewer'), [], 'short');
            self::fail();
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            // @phpstan-ignore argument.type (exercise the runtime PHPDoc boundary)
            new AuthenticatedPrincipal(new UserId(random_bytes(16)), new NormalizedUsername('viewer'), [new \stdClass()]);
            self::fail();
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $valid = [random_bytes(16),new DateTimeImmutable('2026-07-12T10:00:00+02:00'),null,random_bytes(16),AuditEventType::LoginFailed,AuditOutcome::Denied,'user',random_bytes(16),'invalid_credentials',random_bytes(16)];
        $event = new SecurityAuditEvent(...$valid);
        self::assertSame('UTC', $event->occurredAt->getTimezone()->getName());
        self::assertIsString($event->actorSessionId);
        $anonymous = new SecurityAuditEvent(random_bytes(16), new DateTimeImmutable('2026-07-12T10:00:00Z'), null, null, AuditEventType::LoginFailed, AuditOutcome::Denied, null, null, null, random_bytes(16));
        self::assertNull($anonymous->actorSessionId);
        self::assertNull($anonymous->subjectId);
        foreach (['session', 'role', 'target', 'policy', 'selection', 'guest_override', 'backup_request'] as $subjectType) {
            $typed = new SecurityAuditEvent(random_bytes(16), new DateTimeImmutable('2026-07-12T10:00:00Z'), null, null, AuditEventType::LoginFailed, AuditOutcome::Denied, $subjectType, random_bytes(16), null, random_bytes(16));
            self::assertSame($subjectType, $typed->subjectType);
        }
        foreach ([[0 => 'short'],[3 => 'short'],[9 => 'short'],[6 => null],[7 => null],[7 => 'short'],[6 => 'secret',7 => random_bytes(16)],[6 => 'other'],[8 => 'bad reason!']] as $override) {
            $args = array_replace($valid, $override);
            try {
                new SecurityAuditEvent(...$args);
                self::fail();
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
