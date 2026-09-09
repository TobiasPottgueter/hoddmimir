<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Domain\Backup\BackupProblemCode;
use App\Infrastructure\Persistence\MariaDb\DbalBackupProblemRecorder;
use DateTimeImmutable;

final class DbalBackupProblemRecorderTest extends DatabaseTestCase
{
    public function testRunlessPrePostOccurrencesAreIdempotentAndDoNotCreateSyntheticRuns(): void
    {
        $recorder = new DbalBackupProblemRecorder();
        $context = [
            'connection_id' => self::id('connection'),
            'cluster_id' => self::id('cluster'),
            'guest_id' => self::id('guest'),
            'policy_id' => self::id('policy'),
            'target_id' => self::id('target'),
            'attempt' => 1,
            'guest_name' => 'customer-app',
            'guest_type' => 'qemu',
            'vmid' => 101,
            'node_name' => 'node-a',
            'target_label' => 'backup-a',
        ];
        $first = self::id('scheduler-occurrence-1');
        $second = self::id('scheduler-occurrence-2');
        $at = new DateTimeImmutable('2026-07-19T20:00:00Z');

        $recorder->prePost($this->connection(), $context, $first, BackupProblemCode::CapacityBlocked, 'minimum_free_space.insufficient_free_space', $at, $at->modify('+2 minutes'));
        $recorder->prePost($this->connection(), $context, $first, BackupProblemCode::CapacityBlocked, 'minimum_free_space.insufficient_free_space', $at, $at->modify('+2 minutes'));
        $recorder->prePost($this->connection(), $context, $second, BackupProblemCode::CapacityBlocked, 'minimum_free_space.insufficient_free_space', $at->modify('+2 minutes'), $at->modify('+4 minutes'));

        $runCount = $this->connection()->fetchOne('SELECT COUNT(*) FROM backup_runs');
        self::assertTrue(is_int($runCount) || is_string($runCount));
        self::assertSame('0', (string) $runCount);
        $notificationCount = $this->connection()->fetchOne('SELECT COUNT(*) FROM backup_notification_outbox');
        self::assertTrue(is_int($notificationCount) || is_string($notificationCount));
        self::assertSame('2', (string) $notificationCount);
        $consecutiveFailures = $this->connection()->fetchOne('SELECT consecutive_failures FROM backup_problem_states');
        self::assertTrue(is_int($consecutiveFailures) || is_string($consecutiveFailures));
        self::assertSame('2', (string) $consecutiveFailures);
        $rows = $this->connection()->fetchAllAssociative('SELECT occurrence_id, root_request_id, request_id, run_id, notification_kind FROM backup_notification_outbox ORDER BY created_at');
        self::assertCount(2, $rows);
        self::assertSame($first, $rows[0]['occurrence_id']);
        self::assertNull($rows[0]['root_request_id']);
        self::assertNull($rows[0]['request_id']);
        self::assertNull($rows[0]['run_id']);
        self::assertSame('failure', $rows[0]['notification_kind']);
    }

    private static function id(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }
}
