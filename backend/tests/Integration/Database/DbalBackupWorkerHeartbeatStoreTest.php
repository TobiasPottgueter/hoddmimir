<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Backup\Worker\BackupWorkerHeartbeatStatus;
use App\Infrastructure\Persistence\MariaDb\DbalBackupWorkerHeartbeatStore;
use DateTimeImmutable;

final class DbalBackupWorkerHeartbeatStoreTest extends DatabaseTestCase
{
    public function testItUpsertsSanitizedBackupWorkerHealthWithoutExposingTheIdentifier(): void
    {
        $worker = substr(hash('sha256', 'backup-heartbeat-worker', true), 0, 16);
        $now = new DateTimeImmutable('2026-07-13T00:00:00Z');
        $store = new DbalBackupWorkerHeartbeatStore($this->connection(), 30, 'test-build');

        $store->record($worker, BackupWorkerHeartbeatStatus::Busy, 'worker_tick', $now, $now->modify('+5 seconds'));
        $store->record($worker, BackupWorkerHeartbeatStatus::Ready, 'monitored', $now->modify('+1 second'), $now->modify('+6 seconds'));

        $row = $this->connection()->fetchAssociative('SELECT * FROM worker_heartbeats WHERE worker_instance_id=:worker', ['worker' => $worker]);
        self::assertIsArray($row);
        self::assertSame('backup', $row['worker_kind']);
        self::assertSame('ready', $row['status']);
        self::assertSame('monitored', $row['current_activity']);
        self::assertNull($row['current_cycle_token']);
        self::assertSame('test-build', $row['build_version']);
        $ttl = $this->connection()->fetchOne('SELECT TIMESTAMPDIFF(SECOND,heartbeat_at,expires_at) FROM worker_heartbeats WHERE worker_instance_id=:worker', ['worker' => $worker]);
        self::assertTrue(is_int($ttl) || (is_string($ttl) && ctype_digit($ttl)));
        self::assertSame(30, (int) $ttl);
    }
}
