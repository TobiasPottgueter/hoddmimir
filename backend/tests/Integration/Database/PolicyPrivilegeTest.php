<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class PolicyPrivilegeTest extends DatabaseTestCase
{
    private const array TABLES = ['backup_policies', 'backup_policy_assignments', 'backup_policy_guest_overrides'];

    public function testCollectorRemainsReadOnlyAndBackupWorkerGainsOnlyReads(): void
    {
        foreach (['collector', 'backup_worker'] as $kind) {
            $runtime = $this->runtimeConnection($kind);
            try {
                foreach (self::TABLES as $table) {
                    self::assertSame([], $runtime->fetchAllAssociative("SELECT * FROM {$table} LIMIT 0"));
                    $this->assertDenied(static fn () => $runtime->executeStatement("UPDATE {$table} SET id=id WHERE 1=0"));
                    $this->assertDenied(static fn () => $runtime->executeStatement("DELETE FROM {$table} WHERE 1=0"));
                }
            } finally { $runtime->close(); }
        }
    }

    public function testWebHasBoundedPolicyDmlAndCannotRewriteContext(): void
    {
        $web = $this->runtimeConnection('web');
        try {
            self::assertSame(0, $web->executeStatement('UPDATE backup_policies SET display_name=display_name, revision=revision, updated_at=updated_at WHERE 1=0'));
            self::assertSame(0, $web->executeStatement('UPDATE backup_policy_assignments SET selection_value=selection_value, revision=revision WHERE 1=0'));
            self::assertSame(0, $web->executeStatement('UPDATE backup_policy_guest_overrides SET backup_mode=backup_mode, revision=revision WHERE 1=0'));
            foreach (self::TABLES as $table) {
                $this->assertDenied(static fn () => $web->executeStatement("UPDATE {$table} SET connection_id=connection_id WHERE 1=0"));
                $this->assertDenied(static fn () => $web->executeStatement("UPDATE {$table} SET cluster_id=cluster_id WHERE 1=0"));
                $this->assertDenied(static fn () => $web->executeStatement("DELETE FROM {$table} WHERE 1=0"));
            }
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
        try { $operation(); self::fail('A runtime user exceeded bounded policy grants.'); } catch (Exception) { self::addToAssertionCount(1); }
    }
}
