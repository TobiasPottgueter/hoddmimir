<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

final class BackupQueuePrivilegeTest extends DatabaseTestCase
{
    private const array TABLES = [
        'backup_requests',
        'backup_request_events',
        'backup_runs',
        'backup_run_events',
        'backup_run_log_entries',
        'backup_node_slots',
        'backup_target_slots',
        'backup_capacity_reservations',
        'executor_permission_evidence',
        'backup_problem_states',
        'backup_notification_outbox',
    ];

    public function testRuntimeUsersHaveOnlyTheirQueueResponsibilities(): void
    {
        $collector = $this->runtimeConnection('collector');
        $worker = $this->runtimeConnection('backup_worker');
        $web = $this->runtimeConnection('web');
        try {
            self::assertSame(0, $worker->executeStatement('UPDATE worker_heartbeats SET status=status WHERE 1=0'));
            $this->assertDenied(static fn () => $web->executeStatement('UPDATE worker_heartbeats SET status=status WHERE 1=0'));
            foreach (self::TABLES as $table) {
                self::assertSame([], $worker->fetchAllAssociative(sprintf('SELECT * FROM %s LIMIT 0', $table)));
                self::assertSame([], $web->fetchAllAssociative(sprintf('SELECT * FROM %s LIMIT 0', $table)));
                $this->assertDenied(static fn () => $web->executeStatement(sprintf(
                    'UPDATE %s SET %s = %s WHERE 1 = 0',
                    $table,
                    self::identityColumn($table),
                    self::identityColumn($table),
                )));
                if ('backup_problem_states' === $table) {
                    self::assertSame(0, $worker->executeStatement('DELETE FROM backup_problem_states WHERE 1 = 0'));
                } else {
                    $this->assertDenied(static fn () => $worker->executeStatement(sprintf('DELETE FROM %s WHERE 1 = 0', $table)));
                }

                if (in_array($table, ['backup_request_events', 'backup_run_events', 'backup_run_log_entries'], true)) {
                    $this->assertDenied(static fn () => $worker->executeStatement(sprintf(
                        'UPDATE %s SET %s = %s WHERE 1 = 0',
                        $table,
                        self::identityColumn($table),
                        self::identityColumn($table),
                    )));
                }

                if (in_array($table, ['backup_requests', 'backup_request_events'], true)) {
                    self::assertSame([], $collector->fetchAllAssociative(sprintf('SELECT * FROM %s LIMIT 0', $table)));
                    $this->assertDenied(static fn () => $collector->executeStatement(sprintf(
                        'UPDATE %s SET %s = %s WHERE 1 = 0',
                        $table,
                        self::identityColumn($table),
                        self::identityColumn($table),
                    )));
                    $this->assertDenied(static fn () => $collector->executeStatement(sprintf('DELETE FROM %s WHERE 1 = 0', $table)));
                } else {
                    $this->assertDenied(static fn () => $collector->fetchAllAssociative(sprintf('SELECT * FROM %s LIMIT 0', $table)));
                }
            }
        } finally {
            $collector->close();
            $worker->close();
            $web->close();
        }
    }

    public function testBackupWorkerCannotMutateCollectorOwnedProxmoxInventory(): void
    {
        $worker = $this->runtimeConnection('backup_worker');
        try {
            foreach (['guests', 'guest_placements', 'pve_nodes', 'pve_storages', 'pve_node_storage_state'] as $table) {
                $this->assertDenied(static fn () => $worker->executeStatement(sprintf(
                    'UPDATE %s SET %s = %s WHERE 1 = 0',
                    $table,
                    self::identityColumn($table),
                    self::identityColumn($table),
                )));
            }
        } finally {
            $worker->close();
        }
    }

    public function testWebRuntimeCanCancelWithoutReceivingClaimOrLeasePrivileges(): void
    {
        $web = $this->runtimeConnection('web');
        try {
            self::assertSame(0, $web->executeStatement(<<<'SQL'
UPDATE backup_requests
SET state = state,
    cancel_requested_at = cancel_requested_at,
    terminal_code = terminal_code,
    terminal_at = terminal_at,
    revision = revision,
    updated_at = updated_at
WHERE 1 = 0
SQL));

            foreach (['claim_token', 'lease_owner', 'lease_expires_at', 'run_id'] as $workerOwnedColumn) {
                $this->assertDenied(static fn () => $web->executeStatement(sprintf(
                    'UPDATE backup_requests SET %1$s = %1$s WHERE 1 = 0',
                    $workerOwnedColumn,
                )));
            }
        } finally {
            $web->close();
        }
    }

    private static function identityColumn(string $table): string
    {
        return match ($table) {
            'backup_run_log_entries' => 'run_id',
            'backup_node_slots' => 'node_id',
            'backup_target_slots' => 'target_id',
            'backup_capacity_reservations' => 'request_id',
            'executor_permission_evidence' => 'connection_id',
            'backup_problem_states' => 'root_request_id',
            'guest_placements', 'guest_write_states' => 'guest_id',
            'pve_node_storage_state' => 'node_id',
            default => 'id',
        };
    }

    private function runtimeConnection(string $kind): Connection
    {
        $password = file_get_contents(sprintf('/run/secrets/mariadb_%s_password', $kind));
        self::assertIsString($password);

        return DriverManager::getConnection(array_replace($this->connection()->getParams(), [
            'user' => 'hoddmimir_'.$kind,
            'password' => trim($password),
        ]));
    }

    /** @param callable(): mixed $operation */
    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('A runtime database user exceeded its queue privileges.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }
}
