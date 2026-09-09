<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Security\Auth;

use App\Application\Security\Audit\AuditEventStore;
use App\Application\Security\Audit\SecurityAuditEvent;
use App\Application\Security\Audit\SecurityAuditRecorder;
use App\Application\Security\Auth\AuthenticateSession;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\AuthenticationIdentity;
use App\Application\Security\Auth\AuthenticationStore;
use App\Application\Security\Auth\LocalLogin;
use App\Application\Security\Auth\LoginThrottleStore;
use App\Application\Security\Auth\LogoutSession;
use App\Application\Security\Auth\OpaqueToken;
use App\Application\Security\Auth\OpaqueTokenGenerator;
use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\Auth\PasswordHasher;
use App\Application\Security\Auth\SecurityDigest;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Application\Security\Auth\SecurityTransaction;
use App\Application\Security\Auth\StoredWebSession;
use App\Application\Security\Auth\WebSessionStore;
use App\Domain\Security\LoginThrottleState;
use App\Domain\Security\GlobalLoginThrottleState;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserId;
use App\Infrastructure\Security\HmacCsrfTokenDeriver;
use App\Infrastructure\Security\NativeArgon2idPasswordHasher;
use App\Infrastructure\Security\Sha256OpaqueSecretHasher;
use App\Application\Security\PlaintextSecret;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LocalAuthWorkflowTest extends TestCase
{
    public function testUnknownUserStillPerformsExactlyOneArgon2Verification(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-12T10:00:00Z'));
        $native = new NativeArgon2idPasswordHasher();
        $passwords = new CountingPasswordHasher($native);
        $store = new InMemoryLocalAuthStore(new AuthenticationIdentity(
            new UserId(str_repeat('u', 16)),
            new NormalizedUsername('admin'),
            $native->hash(PlaintextSecret::fromString('correct-password')),
            Permission::cases(),
        ));
        $ids = new SequentialSecurityIds();
        $audit = new SecurityAuditRecorder($store, $ids, $clock);
        $login = new LocalLogin($store, $store, $store, $store, $passwords, new SequentialOpaqueTokens(), new HmacCsrfTokenDeriver(str_repeat('k', 32)), new Sha256OpaqueSecretHasher(), $ids, $audit, $clock);

        $ip = inet_pton('192.0.2.20');
        self::assertIsString($ip);
        $this->expectAuthenticationFailure(fn () => $login->authenticate('unknown', 'attempted-password', $ip, str_repeat('c', 16)));
        self::assertSame(1, $passwords->verifyCalls);
        self::assertArrayNotHasKey('unknown|'.bin2hex($ip), $store->throttles);
    }

    public function testLockedIpAndGlobalBudgetsRejectBeforeArgonAndWithoutAuditGrowth(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-12T10:00:00Z'));
        $native = new NativeArgon2idPasswordHasher();
        $passwords = new CountingPasswordHasher($native);
        $store = new InMemoryLocalAuthStore(new AuthenticationIdentity(
            new UserId(str_repeat('u', 16)),
            new NormalizedUsername('admin'),
            $native->hash(PlaintextSecret::fromString('correct-password')),
            Permission::cases(),
        ));
        $ip = inet_pton('192.0.2.21');
        self::assertIsString($ip);
        $store->ipThrottles[bin2hex($ip)] = new LoginThrottleState(
            $clock->now()->modify('-4 minutes'),
            5,
            $clock->now(),
            $clock->now()->modify('+15 minutes'),
        );
        $login = $this->login($store, $passwords, $clock);
        $this->expectAuthenticationFailure(fn () => $login->authenticate('admin', 'correct-password', $ip, str_repeat('c', 16)));
        self::assertSame(0, $passwords->verifyCalls);
        self::assertSame([], $store->audit);

        unset($store->ipThrottles[bin2hex($ip)]);
        $store->throttles['admin|'.bin2hex($ip)] = new LoginThrottleState(
            $clock->now()->modify('-4 minutes'),
            5,
            $clock->now(),
            $clock->now()->modify('+15 minutes'),
        );
        $this->expectAuthenticationFailure(fn () => $login->authenticate('admin', 'correct-password', $ip, str_repeat('c', 16)));
        self::assertSame(0, $passwords->verifyCalls);
        self::assertSame([], $store->audit);

        unset($store->throttles['admin|'.bin2hex($ip)]);
        $store->globalThrottle = new GlobalLoginThrottleState(
            $clock->now()->modify('-4 minutes'),
            1000,
            $clock->now(),
            $clock->now()->modify('+15 minutes'),
        );
        $this->expectAuthenticationFailure(fn () => $login->authenticate('admin', 'correct-password', $ip, str_repeat('c', 16)));
        self::assertSame(0, $passwords->verifyCalls);
        self::assertSame([], $store->audit);
        self::assertSame(3, $store->maintenanceCalls);
    }

    public function testPersistentThrottleAndSessionCsrfCrossCombinationsFailClosed(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-12T10:00:00Z'));
        $passwords = new NativeArgon2idPasswordHasher();
        $store = new InMemoryLocalAuthStore(new AuthenticationIdentity(
            new UserId(str_repeat('u', 16)),
            new NormalizedUsername('admin'),
            $passwords->hash(\App\Application\Security\PlaintextSecret::fromString('correct-password')),
            Permission::cases(),
        ));
        $ids = new SequentialSecurityIds();
        $tokens = new SequentialOpaqueTokens();
        $csrf = new HmacCsrfTokenDeriver(str_repeat('k', 32));
        $digests = new Sha256OpaqueSecretHasher();
        $audit = new SecurityAuditRecorder($store, $ids, $clock);
        $login = new LocalLogin($store, $store, $store, $store, $passwords, $tokens, $csrf, $digests, $ids, $audit, $clock);

        $firstIp = inet_pton('192.0.2.10');
        self::assertIsString($firstIp);
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            try {
                $login->authenticate('admin', 'wrong', $firstIp, str_repeat('c', 16));
                self::fail('Wrong credentials were accepted.');
            } catch (AuthenticationFailed) {
                self::assertSame($attempt, $store->throttles['admin|'.bin2hex($firstIp)]->failureCount);
            }
        }
        $this->expectAuthenticationFailure(fn () => $login->authenticate('admin', 'correct-password', $firstIp, str_repeat('c', 16)));
        self::assertCount(5, array_filter($store->audit, static fn (SecurityAuditEvent $event): bool => 'login_failed' === $event->type->value));

        $secondIp = inet_pton('192.0.2.11');
        $thirdIp = inet_pton('192.0.2.12');
        self::assertIsString($secondIp);
        self::assertIsString($thirdIp);
        $first = $login->authenticate(' ADMIN ', 'correct-password', $secondIp, str_repeat('a', 16));
        $second = $login->authenticate('admin', 'correct-password', $thirdIp, str_repeat('b', 16));
        $sessionAuth = new AuthenticateSession($store, $store, $digests, $csrf, $clock);
        $authenticated = $sessionAuth->authenticate($first->sessionToken);
        self::assertSame('admin', $authenticated->principal->username->value);
        self::assertSame($authenticated->sessionId, $authenticated->principal->sessionId);
        $wrongKeyAuth = new AuthenticateSession($store, $store, $digests, new HmacCsrfTokenDeriver(str_repeat('q', 32)), $clock);
        $this->expectAuthenticationFailure(fn () => $wrongKeyAuth->authenticate($first->sessionToken));

        $logout = new LogoutSession($store, $store, $digests, $audit, $clock);
        $this->expectAuthenticationFailure(fn () => $logout->logout($first->sessionToken, $second->csrfToken, str_repeat('x', 16)));
        $this->expectAuthenticationFailure(fn () => $logout->logout($second->sessionToken, $first->csrfToken, str_repeat('y', 16)));
        self::assertNull($store->sessionFor($first->sessionToken, $digests)->revokedAt);
        self::assertNull($store->sessionFor($second->sessionToken, $digests)->revokedAt);
        $logout->logout($first->sessionToken, $first->csrfToken, str_repeat('z', 16));
        $this->expectAuthenticationFailure(fn () => $sessionAuth->authenticate($first->sessionToken));

        $expiredToken = new OpaqueToken(str_repeat('e', 32));
        $expiredCsrf = $csrf->derive($expiredToken);
        $expiredDigest = $digests->digestToken($expiredToken);
        $store->sessions[bin2hex($expiredDigest->binary())] = new StoredWebSession(
            str_repeat('e', 16),
            $store->sessionFor($second->sessionToken, $digests)->principal,
            $digests->digestToken($expiredCsrf),
            new SessionWindow(
                new DateTimeImmutable('2026-07-11T20:00:00Z'),
                new DateTimeImmutable('2026-07-11T20:00:00Z'),
                new DateTimeImmutable('2026-07-11T20:30:00Z'),
                new DateTimeImmutable('2026-07-12T08:00:00Z'),
            ),
            null,
        );
        $this->expectAuthenticationFailure(fn () => $logout->logout($expiredToken, $expiredCsrf, str_repeat('v', 16)));
        $this->expectAuthenticationFailure(fn () => $logout->logout(new OpaqueToken(str_repeat('n', 32)), new OpaqueToken(str_repeat('n', 32)), str_repeat('n', 16)));
    }

    public function testStoredSessionRejectsMalformedIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StoredWebSession(
            'short',
            new AuthenticatedPrincipal(new UserId(str_repeat('u', 16)), new NormalizedUsername('admin'), []),
            new SecurityDigest(str_repeat('d', 32)),
            new SessionWindow(
                new DateTimeImmutable('2026-07-12T10:00:00Z'),
                new DateTimeImmutable('2026-07-12T10:00:00Z'),
                new DateTimeImmutable('2026-07-12T10:30:00Z'),
                new DateTimeImmutable('2026-07-12T22:00:00Z'),
            ),
            null,
        );
    }

    /** @param callable(): mixed $operation */
    private function expectAuthenticationFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('Authentication unexpectedly succeeded.');
        } catch (AuthenticationFailed) {
            self::addToAssertionCount(1);
        }
    }

    private function login(InMemoryLocalAuthStore $store, PasswordHasher $passwords, FrozenClock $clock): LocalLogin
    {
        $ids = new SequentialSecurityIds();

        return new LocalLogin(
            $store,
            $store,
            $store,
            $store,
            $passwords,
            new SequentialOpaqueTokens(),
            new HmacCsrfTokenDeriver(str_repeat('k', 32)),
            new Sha256OpaqueSecretHasher(),
            $ids,
            new SecurityAuditRecorder($store, $ids, $clock),
            $clock,
        );
    }
}

final class SequentialSecurityIds implements SecurityIdentifierGenerator
{
    private int $next = 1;
    public function generate(): string
    {
        return pack('J', 0).pack('J', $this->next++);
    }
}

final class SequentialOpaqueTokens implements OpaqueTokenGenerator
{
    private int $next = 1;
    public function generate(): OpaqueToken
    {
        $byte = min(255, max(0, $this->next++));

        return new OpaqueToken(str_repeat(chr($byte), 32));
    }
}

final class InMemoryLocalAuthStore implements SecurityTransaction, AuthenticationStore, LoginThrottleStore, WebSessionStore, AuditEventStore
{
    /** @var array<string, LoginThrottleState> */ public array $throttles = [];
    /** @var array<string, LoginThrottleState> */ public array $ipThrottles = [];
    /** @var array<string, StoredWebSession> */ public array $sessions = [];
    /** @var list<SecurityAuditEvent> */ public array $audit = [];
    public GlobalLoginThrottleState $globalThrottle;
    public int $maintenanceCalls = 0;

    public function __construct(private readonly AuthenticationIdentity $identity)
    {
        $at = new DateTimeImmutable('2026-07-12T10:00:00Z');
        $this->globalThrottle = new GlobalLoginThrottleState($at, 0, $at, null);
    }

    public function run(callable $operation): mixed { return $operation(); }
    public function findEnabled(NormalizedUsername $username): ?AuthenticationIdentity { return $username->value === $this->identity->username->value ? $this->identity : null; }
    public function performMaintenance(DateTimeImmutable $now): void { ++$this->maintenanceCalls; }
    public function lockGlobalThrottle(): GlobalLoginThrottleState { return $this->globalThrottle; }
    public function saveGlobalThrottle(GlobalLoginThrottleState $state): void { $this->globalThrottle = $state; }
    public function lockIpThrottle(string $packedIp): ?LoginThrottleState { return $this->ipThrottles[bin2hex($packedIp)] ?? null; }
    public function saveIpThrottle(string $packedIp, LoginThrottleState $state): void { $this->ipThrottles[bin2hex($packedIp)] = $state; }
    public function clearIpThrottle(string $packedIp): void { unset($this->ipThrottles[bin2hex($packedIp)]); }
    public function lockThrottle(NormalizedUsername $username, string $packedIp): ?LoginThrottleState { return $this->throttles[$username->value.'|'.bin2hex($packedIp)] ?? null; }
    public function save(NormalizedUsername $username, string $packedIp, LoginThrottleState $state): void { $this->throttles[$username->value.'|'.bin2hex($packedIp)] = $state; }
    public function clear(NormalizedUsername $username, string $packedIp): void { unset($this->throttles[$username->value.'|'.bin2hex($packedIp)]); }
    public function create(string $sessionId, UserId $userId, SecurityDigest $tokenHash, SecurityDigest $csrfHash, SessionWindow $window): void
    {
        $this->sessions[bin2hex($tokenHash->binary())] = new StoredWebSession(
            $sessionId,
            new AuthenticatedPrincipal($userId, $this->identity->username, $this->identity->permissions),
            $csrfHash,
            $window,
            null,
        );
    }
    public function lockSession(SecurityDigest $tokenHash): ?StoredWebSession { return $this->sessions[bin2hex($tokenHash->binary())] ?? null; }
    public function touch(SecurityDigest $tokenHash, SessionWindow $window): void
    {
        $old = $this->lockSession($tokenHash);
        if (null !== $old) {
            $this->sessions[bin2hex($tokenHash->binary())] = new StoredWebSession($old->id, $old->principal, $old->csrfHash, $window, $old->revokedAt);
        }
    }
    public function revoke(SecurityDigest $tokenHash, DateTimeImmutable $at): void
    {
        $old = $this->lockSession($tokenHash);
        if (null !== $old) {
            $this->sessions[bin2hex($tokenHash->binary())] = new StoredWebSession($old->id, $old->principal, $old->csrfHash, $old->window, $at);
        }
    }
    public function append(SecurityAuditEvent $event): void { $this->audit[] = $event; }
    public function sessionFor(OpaqueToken $token, Sha256OpaqueSecretHasher $digests): StoredWebSession
    {
        $session = $this->lockSession($digests->digestToken($token));
        if (null === $session) {
            throw new RuntimeException('Expected test session is missing.');
        }
        return $session;
    }
}

final class CountingPasswordHasher implements PasswordHasher
{
    public int $verifyCalls = 0;
    public function __construct(private readonly PasswordHasher $inner) {}
    public function hash(PlaintextSecret $password): PasswordHash { return $this->inner->hash($password); }
    public function verify(PlaintextSecret $password, PasswordHash $hash): bool { ++$this->verifyCalls; return $this->inner->verify($password, $hash); }
    public function needsRehash(PasswordHash $hash): bool { return $this->inner->needsRehash($hash); }
}
