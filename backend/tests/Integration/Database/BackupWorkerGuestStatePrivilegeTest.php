<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class BackupWorkerGuestStatePrivilegeTest extends DatabaseTestCase
{
    public function testBackupWorkerCanReadOnlyThePlacementColumnsNeededForRevalidation(): void
    {
        $backupWorker = $this->backupWorkerConnection();
        try {
            self::assertSame([], $backupWorker->fetchAllAssociative(<<<'SQL'
                SELECT guest_id, connection_id, cluster_id, node_id, placement_revision, observed_at
                FROM guest_placements
                LIMIT 0
                SQL));

            foreach ([
                'SELECT sync_run_id FROM guest_placements LIMIT 0',
                'SELECT * FROM guest_placements LIMIT 0',
                'SELECT diskwrite_bytes, observed_at, authoritative_sync_run_id FROM guest_write_states LIMIT 0',
            ] as $sql) {
                $this->assertDenied(static fn () => $backupWorker->fetchAllAssociative($sql));
            }

            foreach ([
                'UPDATE guest_placements SET placement_revision = placement_revision WHERE 1 = 0',
                'DELETE FROM guest_placements WHERE 1 = 0',
                'UPDATE guest_write_states SET diskwrite_bytes = diskwrite_bytes WHERE 1 = 0',
                'DELETE FROM guest_write_states WHERE 1 = 0',
            ] as $sql) {
                $this->assertDenied(static fn () => $backupWorker->executeStatement($sql));
            }

            $this->assertDenied(static fn () => $backupWorker->executeStatement(
                <<<'SQL'
                    INSERT INTO guest_write_states (
                        guest_id, connection_id, cluster_id, diskwrite_bytes,
                        observed_at, authoritative_sync_run_id
                    ) VALUES (
                        :guest_id, :connection_id, :cluster_id, 0,
                        '2026-07-12 10:00:00.000000', :sync_run_id
                    )
                    SQL,
                [
                    'guest_id' => random_bytes(16),
                    'connection_id' => random_bytes(16),
                    'cluster_id' => random_bytes(16),
                    'sync_run_id' => random_bytes(16),
                ],
            ));
        } finally {
            $backupWorker->close();
        }
    }

    private function backupWorkerConnection(): Connection
    {
        $password = file_get_contents('/run/secrets/mariadb_backup_worker_password');
        self::assertIsString($password);

        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => 'hoddmimir_backup_worker',
            'password' => trim($password),
        ]));
    }

    /** @param callable(): mixed $operation */
    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('The backup-worker database user exceeded its bounded revalidation grants.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
