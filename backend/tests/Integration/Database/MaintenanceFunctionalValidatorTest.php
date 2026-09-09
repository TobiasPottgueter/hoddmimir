<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Security\SecretCipher;
use App\Infrastructure\Time\SystemClock;
use App\Infrastructure\Maintenance\MaintenanceFunctionalValidator;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;

final class MaintenanceFunctionalValidatorTest extends DatabaseTestCase
{
    #[DataProvider('roles')]
    public function testRealRoleCanValidateWithoutLeavingAnyProbeData(string $role, string $secret): void
    {
        $password = file_get_contents('/run/secrets/'.$secret);
        self::assertIsString($password);
        $roleConnection = DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => $role, 'password' => trim($password),
        ]));
        $cipher = self::getContainer()->get(SecretCipher::class);
        self::assertInstanceOf(SecretCipher::class, $cipher);
        $tables = ['users', 'user_roles', 'backup_problem_states', 'backup_notification_outbox'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $this->connection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        try {
            (new MaintenanceFunctionalValidator($roleConnection, $cipher, new SystemClock()))->validate();
            self::assertFalse($roleConnection->isTransactionActive());
        } finally {
            $roleConnection->close();
        }
        foreach ($tables as $table) self::assertSame($before[$table], $this->connection()->fetchOne('SELECT COUNT(*) FROM '.$table));
    }

    /** @return iterable<string, array{string, string}> */
    public static function roles(): iterable
    {
        yield 'migration' => ['hoddmimir_migration', 'mariadb_migration_password'];
        yield 'collector' => ['hoddmimir_collector', 'mariadb_collector_password'];
        yield 'backup' => ['hoddmimir_backup_worker', 'mariadb_backup_worker_password'];
        yield 'web' => ['hoddmimir_web', 'mariadb_web_password'];
    }
}
