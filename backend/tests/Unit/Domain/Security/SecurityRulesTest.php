<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Security;

use App\Domain\Security\LastAdministratorGuard;
use App\Domain\Security\GlobalLoginThrottlePolicy;
use App\Domain\Security\GlobalLoginThrottleState;
use App\Domain\Security\LastAdministratorViolation;
use App\Domain\Security\LoginThrottlePolicy;
use App\Domain\Security\LoginThrottleState;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\PasswordPolicy;
use App\Domain\Security\Role;
use App\Domain\Security\RolePermissionSet;
use App\Domain\Security\SessionPolicy;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserDisplayName;
use App\Domain\Security\UserId;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityRulesTest extends TestCase
{
    public function testAdministratorPasswordPolicyHasOnlyClosedByteLengthBounds(): void
    {
        $policy = new PasswordPolicy();
        $policy->assertValid(str_repeat('x', 12));
        $policy->assertValid(str_repeat('x', 128));
        self::addToAssertionCount(2);
        foreach ([str_repeat('x', 11), str_repeat('x', 129)] as $invalid) {
            try {
                $policy->assertValid($invalid);
                self::fail('Invalid administrator password length was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
    public function testIdentityUsernameDisplayAndClosedRolePermissions(): void
    {
        $id = new UserId(str_repeat('a', 16));
        self::assertSame(str_repeat('a', 16), $id->binary());
        self::assertTrue($id->equals(new UserId(str_repeat('a', 16))));
        self::assertFalse($id->equals(new UserId(str_repeat('b', 16))));
        self::assertSame('admin.user', (new NormalizedUsername(' ADMIN.User '))->value);
        self::assertSame('abc', (new NormalizedUsername('abc'))->value);
        self::assertSame(str_repeat('a', 64), (new NormalizedUsername(str_repeat('a', 64)))->value);
        self::assertSame('Tobias', (new UserDisplayName(' Tobias '))->value);
        self::assertSame(str_repeat('a', 190), (new UserDisplayName(str_repeat('a', 190)))->value);
        self::assertSame(Permission::cases(), RolePermissionSet::for(Role::Admin));
        self::assertSame([Permission::InventoryRead], RolePermissionSet::for(Role::Viewer));
    }
    #[DataProvider('invalidIdentities')]
    public function testInvalidIdentityValuesFailClosed(string $kind, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        match($kind) {
            'id' => new UserId($value),'username' => new NormalizedUsername($value),'display' => new UserDisplayName($value),default => throw new \LogicException()
        };
    }
    /** @return iterable<string, array{string,string}> */
    public static function invalidIdentities(): iterable
    {
        yield 'id' => ['id',''];
        yield 'short username' => ['username','ab'];
        yield 'spaced username' => ['username','bad user'];
        yield 'long username' => ['username',str_repeat('a', 65)];
        yield 'blank display' => ['display',' '];
        yield 'long display' => ['display',str_repeat('a', 191)];
    }
    public function testLastAdminGuardProtectsOnlyTheLastEnabledAdministrator(): void
    {
        $guard = new LastAdministratorGuard();
        $guard->assertCanRemove(false, 0);
        $guard->assertCanRemove(true, 2);
        $guard->assertNotSelfLockout(false, true);
        $guard->assertNotSelfLockout(true, false);
        self::addToAssertionCount(4);
        try {
            $guard->assertNotSelfLockout(true, true);
            self::fail();
        } catch (LastAdministratorViolation) {
            self::addToAssertionCount(1);
        }
        try {
            $guard->assertCanRemove(true, 1);
            self::fail();
        } catch (LastAdministratorViolation) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $guard->assertCanRemove(false, -1);
    }
    public function testSessionPolicyUsesExactIdleAbsoluteAndStrictExpiryBoundaries(): void
    {
        $policy = new SessionPolicy();
        $at = new DateTimeImmutable('2026-07-12T10:00:00+02:00');
        $window = $policy->issue($at);
        self::assertSame('2026-07-12T08:30:00+00:00', $window->idleExpiresAt->format('c'));
        self::assertSame('2026-07-12T20:00:00+00:00', $window->absoluteExpiresAt->format('c'));
        self::assertFalse($window->isExpiredAt($window->idleExpiresAt->modify('-1 microsecond')));
        self::assertTrue($window->isExpiredAt($window->idleExpiresAt));
        $nearAbsolute = new SessionWindow($window->issuedAt, $window->absoluteExpiresAt->modify('-10 minutes'), $window->absoluteExpiresAt->modify('-5 minutes'), $window->absoluteExpiresAt);
        $touched = $policy->touch($nearAbsolute, $window->absoluteExpiresAt->modify('-6 minutes'));
        self::assertEquals($window->absoluteExpiresAt, $touched->idleExpiresAt);
    }
    public function testInvalidSessionAndTouchBranchesFailClosed(): void
    {
        $at = new DateTimeImmutable('2026-07-12T10:00:00Z');
        foreach ([[$at->modify('+1 second'),$at,$at->modify('+2 seconds'),$at->modify('+3 seconds')],[$at,$at,$at,$at->modify('+3 seconds')],[$at,$at,$at->modify('+4 seconds'),$at->modify('+3 seconds')]] as $case) {
            try {
                new SessionWindow(...$case);
                self::fail();
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $policy = new SessionPolicy();
        $window = $policy->issue($at);
        foreach ([$window->idleExpiresAt,$at->modify('-1 second')] as $now) {
            try {
                $policy->touch($window, $now);
                self::fail();
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
    public function testLoginThrottleHasStrictFiveInFifteenAndFifteenMinuteLock(): void
    {
        $policy = new LoginThrottlePolicy();
        $at = new DateTimeImmutable('2026-07-12T10:00:00Z');
        $state = $policy->firstFailure($at);
        for ($i = 2;$i <= 5;$i++) {
            $state = $policy->recordFailure($state, $at->modify('+'.($i - 1).' minutes'));
        }
        self::assertSame(5, $state->failureCount);
        $lockedUntil = $state->lockedUntil;
        self::assertInstanceOf(DateTimeImmutable::class, $lockedUntil);
        self::assertTrue($state->isLockedAt($lockedUntil->modify('-1 microsecond')));
        self::assertFalse($state->isLockedAt($lockedUntil));
        self::assertSame($state, $policy->recordFailure($state, $at->modify('+5 minutes')));
        $reset = $policy->recordFailure($state, $lockedUntil);
        self::assertSame(1, $reset->failureCount);
        self::assertNull($reset->lockedUntil);
    }
    public function testThrottleStateRejectsInvalidShapes(): void
    {
        $at = new DateTimeImmutable('2026-07-12T10:00:00Z');
        foreach ([[0,$at,null],[6,$at,null],[1,$at->modify('-1 second'),null],[1,$at,$at->modify('+1 minute')],[5,$at,null]] as [$count,$last,$lock]) {
            try {
                new LoginThrottleState($at, $count, $last, $lock);
                self::fail();
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testGlobalLoginThrottleBoundsDistributedFailuresAndResetsAtItsWindowBoundary(): void
    {
        $policy = new GlobalLoginThrottlePolicy();
        $at = new DateTimeImmutable('2026-07-12T10:00:00Z');
        $state = new GlobalLoginThrottleState($at, 999, $at->modify('+14 minutes'), null);
        $locked = $policy->recordFailure($state, $at->modify('+14 minutes 1 second'));
        self::assertSame(1000, $locked->failureCount);
        self::assertTrue($locked->isLockedAt($locked->lockedUntil?->modify('-1 microsecond') ?? $at));
        self::assertSame($locked, $policy->recordFailure($locked, $at->modify('+14 minutes 2 seconds')));
        self::assertFalse($locked->isLockedAt($locked->lockedUntil ?? $at));
        $reset = $policy->recordFailure($locked, $at->modify('+30 minutes'));
        self::assertSame(1, $reset->failureCount);
        self::assertNull($reset->lockedUntil);
    }

    public function testGlobalLoginThrottleStateRejectsInvalidShapes(): void
    {
        $at = new DateTimeImmutable('2026-07-12T10:00:00Z');
        foreach ([
            [-1, $at, null],
            [1001, $at, null],
            [0, $at->modify('-1 second'), null],
            [0, $at->modify('+15 minutes'), null],
            [1, $at, $at->modify('+1 minute')],
            [1000, $at, null],
        ] as [$count, $last, $lock]) {
            try {
                new GlobalLoginThrottleState($at, $count, $last, $lock);
                self::fail();
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
