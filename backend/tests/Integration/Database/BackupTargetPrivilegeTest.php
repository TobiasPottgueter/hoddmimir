<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class BackupTargetPrivilegeTest extends DatabaseTestCase
{
    public function testCollectorRemainsReadOnlyAndBackupWorkerGainsOnlyReads(): void
    {
        foreach (['collector', 'backup_worker'] as $kind) {
            $runtime = $this->runtimeConnection($kind);
            try {
                foreach (['backup_targets', 'backup_target_allowed_nodes'] as $table) {
                    self::assertSame([], $runtime->fetchAllAssociative("SELECT * FROM {$table} LIMIT 0"));
                    $this->assertDenied(static fn () => $runtime->executeStatement("UPDATE {$table} SET target_id = target_id WHERE 1=0"));
                    $this->assertDenied(static fn () => $runtime->executeStatement("DELETE FROM {$table} WHERE 1=0"));
                }
            } finally { $runtime->close(); }
        }
    }

    public function testWebHasBoundedTargetDmlAndCannotRewriteIdentity(): void
    {
        $web = $this->runtimeConnection('web');
        try {
            self::assertSame(0, $web->executeStatement('UPDATE backup_targets SET display_name = display_name, revision = revision, updated_at = updated_at WHERE 1=0'));
            self::assertSame(0, $web->executeStatement('DELETE FROM backup_target_allowed_nodes WHERE 1=0'));
            $this->assertDenied(static fn () => $web->executeStatement('UPDATE backup_targets SET connection_id = connection_id WHERE 1=0'));
            $this->assertDenied(static fn () => $web->executeStatement('UPDATE backup_targets SET storage_id = storage_id WHERE 1=0'));
            $this->assertDenied(static fn () => $web->executeStatement('UPDATE backup_target_allowed_nodes SET node_id = node_id WHERE 1=0'));
            $this->assertDenied(static fn () => $web->executeStatement('DELETE FROM backup_targets WHERE 1=0'));
        } finally { $web->close(); }
    }

    private function runtimeConnection(string $kind): Connection
    {
        $password = file_get_contents('/run/secrets/mariadb_'.$kind.'_password');
        self::assertIsString($password);
        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => 'backup_worker' === $kind ? 'hoddmimir_backup_worker' : 'hoddmimir_'.$kind,
            'password' => trim($password),
        ]));
    }

    private function assertDenied(callable $operation): void
    {
        try { $operation(); self::fail('A runtime user exceeded bounded target grants.'); } catch (Exception) { self::addToAssertionCount(1); }
    }
}
