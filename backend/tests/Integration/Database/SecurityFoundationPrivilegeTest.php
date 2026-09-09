<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class SecurityFoundationPrivilegeTest extends DatabaseTestCase
{
    private const array WEB = [
        'users' => 'USER_WRITE',
        'roles' => 'SELECT',
        'permissions' => 'SELECT',
        'user_roles' => 'SELECT, INSERT, DELETE',
        'role_permissions' => 'SELECT',
        'web_sessions' => 'COLUMN_UPDATE',
        'login_attempts' => 'SELECT, INSERT, UPDATE, DELETE',
        'login_ip_attempts' => 'SELECT, INSERT, UPDATE, DELETE',
        'login_global_throttle' => 'SELECT, UPDATE',
        'audit_events' => 'SELECT, INSERT',
        'security_command_idempotency' => 'SELECT, INSERT',
    ];

    private const array IDENTITIES = [
        'users' => 'id',
        'roles' => 'id',
        'permissions' => 'id',
        'user_roles' => 'user_id',
        'role_permissions' => 'role_id',
    ];

    public function testWebHasExactFoundationGrantsWithOnlyBootstrapUserWrites(): void
    {
        $web = $this->runtime('web');
        try {
            $grants = $web->fetchFirstColumn('SHOW GRANTS FOR CURRENT_USER()');
            foreach (self::WEB as $table => $expected) {
                $matches = array_values(array_filter(
                    $grants,
                    static fn (mixed $grant): bool => is_string($grant)
                        && str_contains($grant, '.`'.$table.'`'),
                ));
                self::assertCount(1, $matches);
                if ('COLUMN_UPDATE' === $expected) {
                    self::assertStringContainsString('GRANT SELECT, INSERT, UPDATE', $matches[0]);
                    foreach (['last_seen_at', 'idle_expires_at', 'revoked_at'] as $column) {
                        self::assertStringContainsString($column, $matches[0]);
                    }
                    self::assertStringNotContainsString('token_hash', $matches[0]);
                    self::assertStringNotContainsString('user_id', $matches[0]);
                    self::assertStringNotContainsString('issued_at', $matches[0]);
                    self::assertStringNotContainsString('DELETE', $matches[0]);
                } elseif ('USER_WRITE' === $expected) {
                    self::assertStringContainsString('GRANT SELECT, INSERT, UPDATE', $matches[0]);
                    foreach (['display_name', 'password_hash', 'enabled', 'revision', 'updated_at', 'disabled_at'] as $column) {
                        self::assertStringContainsString($column, $matches[0]);
                    }
                    self::assertStringNotContainsString('username', $matches[0]);
                    self::assertStringNotContainsString('last_login_at', $matches[0]);
                    self::assertStringNotContainsString('DELETE', $matches[0]);
                } else {
                    self::assertStringContainsString('GRANT '.$expected.' ON', $matches[0]);
                }
            }
            foreach (self::IDENTITIES as $table => $identity) {
                $this->denied(static fn () => $web->executeStatement(sprintf(
                    'UPDATE %s SET %s = %s WHERE 1 = 0',
                    $table,
                    $identity,
                    $identity,
                )));
            }
            foreach (['roles', 'permissions', 'role_permissions'] as $table) {
                $this->denied(static fn () => $web->executeStatement(sprintf(
                    'INSERT INTO %s SELECT * FROM %s WHERE 1 = 0',
                    $table,
                    $table,
                )));
            }
            foreach (['users'] as $table) {
                $this->denied(static fn () => $web->executeStatement(sprintf(
                    'DELETE FROM %s WHERE 1 = 0',
                    $table,
                )));
            }
            foreach (['token_hash', 'user_id', 'issued_at', 'absolute_expires_at'] as $column) {
                $this->denied(static fn () => $web->executeStatement(sprintf(
                    'UPDATE web_sessions SET %s = %s WHERE 1 = 0',
                    $column,
                    $column,
                )));
            }
            self::assertTrue((bool) array_filter(
                $grants,
                static fn (mixed $grant): bool => is_string($grant)
                    && str_contains($grant, 'EXECUTE ON PROCEDURE')
                    && str_contains($grant, '`prune_login_security_state`'),
            ));
            self::assertSame(0, $web->executeStatement(
                "CALL prune_login_security_state('1900-01-01 00:00:00.000000', '1900-01-01 00:00:00.000000')",
            ));
            $this->denied(static fn () => $web->executeStatement('DELETE FROM login_global_throttle WHERE 1 = 0'));
            $this->denied(static fn () => $web->executeStatement('INSERT INTO login_global_throttle SELECT * FROM login_global_throttle WHERE 1 = 0'));
        } finally {
            $web->close();
        }
    }

    public function testWorkersHaveNoSecurityTableAccessAndAuditIsAppendOnly(): void
    {
        foreach (['collector', 'backup_worker'] as $kind) {
            $database = $this->runtime($kind);
            try {
                foreach (array_keys(self::WEB) as $table) {
                    $this->denied(static fn () => $database->fetchAllAssociative(
                        sprintf('SELECT * FROM %s LIMIT 0', $table),
                    ));
                }
            } finally {
                $database->close();
            }
        }

        $web = $this->runtime('web');
        try {
            $this->denied(static fn () => $web->executeStatement(
                'UPDATE audit_events SET occurred_at = occurred_at WHERE 1 = 0',
            ));
            $this->denied(static fn () => $web->executeStatement('DELETE FROM audit_events WHERE 1 = 0'));
            $this->denied(static fn () => $web->executeStatement('DELETE FROM web_sessions WHERE 1 = 0'));
        } finally {
            $web->close();
        }
    }

    private function runtime(string $kind): Connection
    {
        $password = file_get_contents('/run/secrets/mariadb_'.$kind.'_password');
        self::assertIsString($password);
        $user = 'backup_worker' === $kind ? 'hoddmimir_backup_worker' : 'hoddmimir_'.$kind;

        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => $user,
            'password' => trim($password),
        ]));
    }

    /** @param callable(): mixed $operation */
    private function denied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Runtime security grants exceeded.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
