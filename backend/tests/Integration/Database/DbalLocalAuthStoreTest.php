<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Security\Auth\SecurityDigest;
use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;
use App\Application\Security\Audit\SecurityAuditEvent;
use App\Application\Security\Auth\PasswordHash;
use App\Domain\Security\LoginThrottleState;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserDisplayName;
use App\Domain\Security\UserId;
use DateTimeImmutable;
use App\Infrastructure\Persistence\MariaDb\DbalLocalAuthStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalLocalAuthStoreTest extends KernelTestCase
{
    private Connection $setup;
    private string $userId;
    private string $sessionId;
    private string $tokenHash;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->setup = $connection;
        $this->userId = random_bytes(16);
        $this->sessionId = random_bytes(16);
        $this->tokenHash = random_bytes(32);
        $now = '2026-07-12 10:00:00.000000';
        $this->setup->insert('users', [
            'id' => $this->userId,
            'username' => 'race-'.bin2hex(random_bytes(4)),
            'display_name' => 'Race Test',
            'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$abcdefghijklmnop$abcdefghijklmnopqrstuvwxyz0123456789',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $adminRole = $this->setup->fetchOne("SELECT id FROM roles WHERE role_name = 'admin'");
        self::assertIsString($adminRole);
        $this->setup->insert('user_roles', ['user_id' => $this->userId, 'role_id' => $adminRole, 'assigned_at' => $now, 'assigned_by_user_id' => $this->userId]);
        $this->setup->insert('web_sessions', [
            'id' => $this->sessionId,
            'user_id' => $this->userId,
            'token_hash' => $this->tokenHash,
            'csrf_secret_hash' => random_bytes(32),
            'issued_at' => $now,
            'last_seen_at' => $now,
            'idle_expires_at' => '2026-07-12 10:30:00.000000',
            'absolute_expires_at' => '2026-07-12 22:00:00.000000',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->setup)) {
            $this->setup->executeStatement('DELETE FROM audit_events WHERE actor_user_id = ?', [$this->userId]);
            $this->setup->executeStatement('DELETE FROM web_sessions WHERE id = ?', [$this->sessionId]);
            $this->setup->executeStatement('DELETE FROM user_roles WHERE user_id = ?', [$this->userId]);
            $this->setup->executeStatement('DELETE FROM users WHERE id = ?', [$this->userId]);
            $this->setup->close();
        }
        parent::tearDown();
    }

    public function testSessionLookupUsesExclusiveRowLockToSerializeTouchAndRevokeRaces(): void
    {
        $first = DriverManager::getConnection($this->setup->getParams());
        $second = DriverManager::getConnection($this->setup->getParams());
        try {
            $first->beginTransaction();
            $locked = (new DbalLocalAuthStore($first))->lockSession(new SecurityDigest($this->tokenHash));
            self::assertNotNull($locked);

            $second->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            $second->beginTransaction();
            try {
                (new DbalLocalAuthStore($second))->lockSession(new SecurityDigest($this->tokenHash));
                self::fail('A concurrent session mutation bypassed SELECT FOR UPDATE.');
            } catch (Exception) {
                self::addToAssertionCount(1);
            }
        } finally {
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            $second->close();
            $first->close();
        }
    }

    public function testAuthenticationStoreLifecyclePersistsAndReadsAllSecurityState(): void
    {
        $store = new DbalLocalAuthStore($this->setup);
        $usernameValue = $this->setup->fetchOne('SELECT username FROM users WHERE id = ?', [$this->userId]);
        self::assertIsString($usernameValue);
        $username = new NormalizedUsername($usernameValue);
        $ip = inet_pton('192.0.2.27');
        self::assertIsString($ip);

        self::assertSame('transaction-result', $store->run(static fn (): string => 'transaction-result'));
        $store->performMaintenance(new DateTimeImmutable('2026-07-12T10:00:00Z'));
        $global = $store->lockGlobalThrottle();
        self::assertGreaterThanOrEqual(0, $global->failureCount);
        $globalAt = new DateTimeImmutable('2026-07-12T10:00:00Z');
        $store->saveGlobalThrottle(new \App\Domain\Security\GlobalLoginThrottleState($globalAt, 1, $globalAt, null));
        self::assertSame(1, $store->lockGlobalThrottle()->failureCount);

        self::assertNull($store->lockIpThrottle($ip));
        $ipStartedAt = new DateTimeImmutable('2026-07-12T10:00:00Z');
        $store->saveIpThrottle($ip, new LoginThrottleState($ipStartedAt, 1, $ipStartedAt, null));
        self::assertSame(1, $store->lockIpThrottle($ip)?->failureCount);
        $store->clearIpThrottle($ip);
        self::assertNull($store->lockIpThrottle($ip));
        $identity = $store->findEnabled($username);
        self::assertNotNull($identity);
        self::assertSame($usernameValue, $identity->username->value);
        self::assertNotEmpty($identity->permissions);
        self::assertNull($store->findEnabled(new NormalizedUsername('missing-user')));

        self::assertNull($store->lockThrottle($username, $ip));
        $startedAt = new DateTimeImmutable('2026-07-12T10:01:00+00:00');
        $lastFailedAt = new DateTimeImmutable('2026-07-12T10:02:00+00:00');
        $store->save($username, $ip, new LoginThrottleState($startedAt, 2, $lastFailedAt, null));
        $throttle = $store->lockThrottle($username, $ip);
        self::assertNotNull($throttle);
        self::assertSame(2, $throttle->failureCount);
        self::assertNull($throttle->lockedUntil);

        $lockedUntil = $lastFailedAt->modify('+15 minutes');
        $store->save($username, $ip, new LoginThrottleState($startedAt, 5, $lastFailedAt, $lockedUntil));
        self::assertSame($lockedUntil->format('U.u'), $store->lockThrottle($username, $ip)?->lockedUntil?->format('U.u'));
        $store->clear($username, $ip);
        self::assertNull($store->lockThrottle($username, $ip));

        $createdSessionId = random_bytes(16);
        $createdToken = new SecurityDigest(random_bytes(32));
        $csrf = new SecurityDigest(random_bytes(32));
        $window = new SessionWindow(
            new DateTimeImmutable('2026-07-12T11:00:00+00:00'),
            new DateTimeImmutable('2026-07-12T11:01:00+00:00'),
            new DateTimeImmutable('2026-07-12T11:31:00+00:00'),
            new DateTimeImmutable('2026-07-12T23:00:00+00:00'),
        );
        $store->create($createdSessionId, new UserId($this->userId), $createdToken, $csrf, $window);
        $created = $store->lockSession($createdToken);
        self::assertNotNull($created);
        self::assertSame($createdSessionId, $created->id);
        self::assertNull($created->revokedAt);

        $touchedWindow = new SessionWindow(
            $window->issuedAt,
            new DateTimeImmutable('2026-07-12T11:05:00+00:00'),
            new DateTimeImmutable('2026-07-12T11:35:00+00:00'),
            $window->absoluteExpiresAt,
        );
        $store->touch($createdToken, $touchedWindow);
        self::assertSame('2026-07-12 11:05:00.000000', $store->lockSession($createdToken)?->window->lastSeenAt->format('Y-m-d H:i:s.u'));
        $revokedAt = new DateTimeImmutable('2026-07-12T11:06:00+00:00');
        $store->revoke($createdToken, $revokedAt);
        self::assertSame($revokedAt->format('U.u'), $store->lockSession($createdToken)?->revokedAt?->format('U.u'));
        self::assertNull($store->lockSession(new SecurityDigest(random_bytes(32))));

        $eventId = random_bytes(16);
        $correlationId = random_bytes(16);
        $store->append(new SecurityAuditEvent(
            $eventId,
            new DateTimeImmutable('2026-07-12T11:07:00+00:00'),
            new UserId($this->userId),
            $this->sessionId,
            AuditEventType::SessionRevoked,
            AuditOutcome::Succeeded,
            'session',
            $createdSessionId,
            null,
            $correlationId,
        ));
        self::assertSame('session_revoked', $this->setup->fetchOne('SELECT event_type FROM audit_events WHERE id = ?', [$eventId]));

        self::assertGreaterThanOrEqual(1, $store->countUsers());
        $admin = $store->findAdmin($username);
        self::assertNotNull($admin);
        self::assertSame($usernameValue, $admin->username->value);
        self::assertNull($store->findAdmin(new NormalizedUsername('missing-admin')));

        $newAdminId = new UserId(random_bytes(16));
        $newAdminName = new NormalizedUsername('created-admin-'.bin2hex(random_bytes(3)));
        $store->createAdmin(
            $newAdminId,
            $newAdminName,
            new UserDisplayName('Created Admin'),
            new PasswordHash('$argon2id$v=19$m=65536,t=4,p=1$abcdefghijklmnop$abcdefghijklmnopqrstuvwxyz0123456789'),
            new DateTimeImmutable('2026-07-12T11:08:00+00:00'),
        );
        self::assertSame($newAdminName->value, $store->findAdmin($newAdminName)?->username->value);

        $this->setup->executeStatement('DELETE FROM audit_events WHERE id = ?', [$eventId]);
        $this->setup->executeStatement('DELETE FROM web_sessions WHERE id = ?', [$createdSessionId]);
        $this->setup->executeStatement('DELETE FROM user_roles WHERE user_id = ?', [$newAdminId->binary()]);
        $this->setup->executeStatement('DELETE FROM users WHERE id = ?', [$newAdminId->binary()]);
        $this->setup->executeStatement('UPDATE login_global_throttle SET window_started_at=UTC_TIMESTAMP(6), failure_count=0, last_failed_at=UTC_TIMESTAMP(6), locked_until=NULL, updated_at=UTC_TIMESTAMP(6) WHERE singleton_id=1');
    }

    public function testLoginMaintenanceDeletesOnlyBoundedExpiredAnonymousSecurityState(): void
    {
        $store = new DbalLocalAuthStore($this->setup);
        $stale = '2026-05-01 00:00:00.000000';
        $recent = '2026-07-12 09:59:00.000000';
        $staleIp = inet_pton('192.0.2.31');
        $recentIp = inet_pton('192.0.2.32');
        self::assertIsString($staleIp);
        self::assertIsString($recentIp);
        foreach ([[$staleIp, $stale], [$recentIp, $recent]] as [$ip, $time]) {
            $this->setup->insert('login_ip_attempts', [
                'ip_address' => $ip,
                'window_started_at' => $time,
                'failure_count' => 1,
                'last_failed_at' => $time,
                'locked_until' => null,
                'updated_at' => $time,
            ]);
        }
        $staleAudit = random_bytes(16);
        $recentAudit = random_bytes(16);
        foreach ([[$staleAudit, $stale], [$recentAudit, $recent]] as [$id, $time]) {
            $this->setup->insert('audit_events', [
                'id' => $id,
                'occurred_at' => $time,
                'actor_user_id' => null,
                'actor_session_id' => null,
                'event_type' => 'login_failed',
                'outcome' => 'denied',
                'subject_type' => null,
                'subject_id' => null,
                'reason_code' => 'authentication_failed',
                'correlation_id' => random_bytes(16),
            ]);
        }

        $store->performMaintenance(new DateTimeImmutable('2026-07-12T10:00:00Z'));
        self::assertSame(0, $this->setup->fetchOne('SELECT COUNT(*) FROM login_ip_attempts WHERE ip_address = ?', [$staleIp]));
        self::assertSame(1, $this->setup->fetchOne('SELECT COUNT(*) FROM login_ip_attempts WHERE ip_address = ?', [$recentIp]));
        self::assertSame(0, $this->setup->fetchOne('SELECT COUNT(*) FROM audit_events WHERE id = ?', [$staleAudit]));
        self::assertSame(1, $this->setup->fetchOne('SELECT COUNT(*) FROM audit_events WHERE id = ?', [$recentAudit]));

        $this->setup->delete('login_ip_attempts', ['ip_address' => $recentIp]);
        $this->setup->delete('audit_events', ['id' => $recentAudit]);
    }
}
