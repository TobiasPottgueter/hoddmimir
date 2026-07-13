<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class ShadowBackupStatePrivilegeTest extends DatabaseTestCase
{
    public function testGuestBackupStateUsesLeastPrivilegePerRuntime(): void
    {
        $collector = $this->runtime('collector'); $web = $this->runtime('web'); $backup = $this->runtime('backup_worker');
        try {
            foreach ([$collector, $web, $backup] as $connection) {
                self::assertSame([], $connection->fetchAllAssociative('SELECT * FROM guest_backup_state LIMIT 0'));
            }
            self::assertSame([], $backup->fetchAllAssociative('SELECT * FROM guest_write_counter_resets LIMIT 0'));
            self::assertSame([], $web->fetchAllAssociative('SELECT * FROM guest_write_counter_resets LIMIT 0'));
            self::assertSame([], $collector->fetchAllAssociative('SELECT * FROM guest_write_counter_resets LIMIT 0'));
            self::assertSame(0, $collector->executeStatement('UPDATE guest_backup_state SET baseline_bytes = baseline_bytes WHERE 1=0'));
            self::assertSame(0, $backup->executeStatement('UPDATE guest_backup_state SET baseline_bytes = baseline_bytes WHERE 1=0'));
            $this->assertDenied(static fn () => $web->executeStatement('UPDATE guest_backup_state SET baseline_bytes = baseline_bytes WHERE 1=0'));
            $this->assertDenied(static fn () => $collector->executeStatement('DELETE FROM guest_backup_state WHERE 1=0'));
            $this->assertDenied(static fn () => $web->executeStatement('INSERT INTO guest_write_counter_resets (id) VALUES (NULL)'));
            $this->assertDenied(static fn () => $backup->executeStatement('INSERT INTO guest_write_counter_resets (id) VALUES (NULL)'));
        } finally { $collector->close(); $web->close(); $backup->close(); }
    }

    private function runtime(string $kind): Connection
    {
        $password = file_get_contents('/run/secrets/mariadb_'.$kind.'_password'); self::assertIsString($password);
        return DriverManager::getConnection(array_replace($this->connection()->getParams(), ['user' => 'backup_worker' === $kind ? 'hoddmimir_backup_worker' : 'hoddmimir_'.$kind, 'password' => trim($password)]));
    }
    private function assertDenied(callable $operation): void { try { $operation(); self::fail('A runtime exceeded shadow backup-state grants.'); } catch (Exception) { self::addToAssertionCount(1); } }
}
