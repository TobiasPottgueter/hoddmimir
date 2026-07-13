<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Exception;

final class SecurityFoundationSchemaTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-12 10:00:00.000000';
    public function testClosedRolesPermissionsAndLeastPrivilegeMappingAreSeeded(): void
    {
        self::assertSame(['admin','viewer'], $this->connection()->fetchFirstColumn('SELECT role_name FROM roles ORDER BY role_name'));
        self::assertSame(['audit.read','backup_configuration.manage','backup_operations.manage','inventory.read','security.manage'], $this->connection()->fetchFirstColumn('SELECT permission_name FROM permissions ORDER BY permission_name'));
        $rows = $this->connection()->fetchAllAssociative('SELECT r.role_name,p.permission_name FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id ORDER BY r.role_name,p.permission_name');
        self::assertCount(6, $rows);
        self::assertSame(['inventory.read'], array_column(array_filter($rows, static fn (array $row): bool => 'viewer' === $row['role_name']), 'permission_name'));
    }
    public function testUsersSessionsThrottleAndAuditAcceptOnlyCanonicalShapes(): void
    {
        $user = random_bytes(16);
        $this->connection()->insert('users', $this->user($user));
        $admin = $this->connection()->fetchOne("SELECT id FROM roles WHERE role_name='admin'");
        self::assertIsString($admin);
        $this->connection()->insert('user_roles', ['user_id' => $user,'role_id' => $admin,'assigned_at' => self::NOW,'assigned_by_user_id' => null]);
        $session = random_bytes(16);
        $this->connection()->insert('web_sessions', ['id' => $session,'user_id' => $user,'token_hash' => random_bytes(32),'csrf_secret_hash' => random_bytes(32),'issued_at' => self::NOW,'last_seen_at' => self::NOW,'idle_expires_at' => '2026-07-12 10:30:00.000000','absolute_expires_at' => '2026-07-12 22:00:00.000000','revoked_at' => null]);
        $this->connection()->insert('login_attempts', ['username' => 'admin','ip_address' => inet_pton('2001:db8::1'),'window_started_at' => self::NOW,'failure_count' => 5,'last_failed_at' => '2026-07-12 10:04:00.000000','locked_until' => '2026-07-12 10:19:00.000000','updated_at' => '2026-07-12 10:04:00.000000']);
        $this->connection()->insert('login_ip_attempts', ['ip_address' => inet_pton('2001:db8::2'),'window_started_at' => self::NOW,'failure_count' => 5,'last_failed_at' => '2026-07-12 10:04:00.000000','locked_until' => '2026-07-12 10:19:00.000000','updated_at' => '2026-07-12 10:04:00.000000']);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM login_global_throttle WHERE singleton_id=1'));
        $this->connection()->insert('audit_events', ['id' => random_bytes(16),'occurred_at' => self::NOW,'actor_user_id' => $user,'actor_session_id' => $session,'event_type' => 'login_succeeded','outcome' => 'succeeded','subject_type' => 'session','subject_id' => $session,'reason_code' => null,'correlation_id' => random_bytes(16)]);
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_events');
        self::assertTrue(is_int($count) || is_string($count) && ctype_digit($count));
        self::assertSame(1, (int)$count);
        self::assertNotContains('details_json', $this->connection()->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='audit_events'"));
    }
    public function testSecurityChecksAndForeignKeysFailClosed(): void
    {
        foreach ([
            fn () => $this->connection()->insert('users', $this->user(random_bytes(16), ['username' => 'Admin'])),
            fn () => $this->connection()->insert('users', $this->user(random_bytes(16), ['password_hash' => 'bcrypt'])),
            fn () => $this->connection()->insert('users', $this->user(random_bytes(16), ['enabled' => 0,'disabled_at' => null])),
            fn () => $this->connection()->insert('roles', ['id' => random_bytes(16),'role_name' => 'operator','display_name' => 'Operator']),
            fn () => $this->connection()->insert('permissions', ['id' => random_bytes(16),'permission_name' => 'root.all']),
        ] as $operation) {
            $this->denied($operation);
        }
        $user = random_bytes(16);
        $this->connection()->insert('users', $this->user($user));
        foreach ([
            fn () => $this->connection()->insert('web_sessions', ['id' => random_bytes(16),'user_id' => $user,'token_hash' => random_bytes(32),'csrf_secret_hash' => random_bytes(32),'issued_at' => self::NOW,'last_seen_at' => self::NOW,'idle_expires_at' => '2026-07-12 10:31:00.000000','absolute_expires_at' => '2026-07-12 22:00:00.000000']),
            fn () => $this->connection()->insert('login_attempts', ['username' => 'admin','ip_address' => 'x','window_started_at' => self::NOW,'failure_count' => 5,'last_failed_at' => self::NOW,'locked_until' => '2026-07-12 10:14:00.000000','updated_at' => self::NOW]),
            fn () => $this->connection()->insert('login_ip_attempts', ['ip_address' => 'x','window_started_at' => self::NOW,'failure_count' => 5,'last_failed_at' => self::NOW,'locked_until' => '2026-07-12 10:15:00.000000','updated_at' => self::NOW]),
            fn () => $this->connection()->update('login_global_throttle', ['failure_count' => 1001], ['singleton_id' => 1]),
            fn () => $this->connection()->insert('audit_events', ['id' => random_bytes(16),'occurred_at' => self::NOW,'actor_user_id' => null,'actor_session_id' => null,'event_type' => 'secret_read','outcome' => 'succeeded','subject_type' => null,'subject_id' => null,'reason_code' => null,'correlation_id' => random_bytes(16)]),
        ] as $operation) {
            $this->denied($operation);
        }
    }
    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function user(string $id, array $override = []): array
    {
        return array_replace(['id' => $id,'username' => 'admin','display_name' => 'Administrator','password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$abcdefghijklmnop$abcdefghijklmnopqrstuvwxyz0123456789','enabled' => 1,'revision' => 1,'created_at' => self::NOW,'updated_at' => self::NOW,'disabled_at' => null,'last_login_at' => null], $override);
    }
    /** @param callable():mixed $operation */
    private function denied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Security schema accepted an invalid row.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
