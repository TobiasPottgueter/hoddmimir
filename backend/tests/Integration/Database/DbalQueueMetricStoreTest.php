<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Backup\Metrics\QueueMetricWindow;
use App\Infrastructure\Persistence\MariaDb\DbalQueueMetricStore;
use App\Infrastructure\Persistence\MariaDb\MariaDbQaFixtureSeeder;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;

final class DbalQueueMetricStoreTest extends DatabaseTestCase
{
    private const string USER = 'metric-user-0001';

    protected function setUp(): void
    {
        parent::setUp();
        // Readiness and collector tests may leave real current-time samples.
        // This transaction owns a fixed historical window and rolls its cleanup back.
        $this->connection()->executeStatement('DELETE FROM queue_metric_ticks');
    }

    public function testSnapshotIsIdempotentPerSlotIncludesZeroTargetsAndAggregatesPeaks(): void
    {
        $at = new DateTimeImmutable('2026-07-13T00:00:00Z');
        $this->seedQaFixture(new FrozenClock($at));
        $id = self::uuid('c0000000-0000-4000-8000-000000000002');
        $this->seedPendingRequest($id);
        // Terminal unknown results are historical evidence, not an occupied queue slot.
        $this->connection()->update('backup_requests', ['state'=>'unknown'], ['id'=>self::uuid('c0000000-0000-4000-8000-000000000001')]);
        $store = new DbalQueueMetricStore($this->connection());
        $store->sample($at, $at->modify('-30 days'));
        $this->connection()->update('backup_requests', ['state'=>'leased', 'claim_token'=>str_repeat('t',16), 'claim_fence'=>1, 'lease_owner'=>str_repeat('w',16), 'lease_issued_at'=>'2026-07-13 00:00:00.000000', 'lease_expires_at'=>'2026-07-13 00:05:00.000000'], ['id'=>$id]);
        $store->sample($at, $at->modify('-30 days'));
        $store->sample($at->modify('+2 minutes'), $at->modify('-30 days'));
        $history = $store->history(new QueueMetricWindow(168, $at->modify('+15 minutes')), null);
        self::assertCount(1, $history);
        self::assertSame(2, $history[0]['samples']);
        self::assertSame(0.5, $history[0]['waitingAverage']);
        self::assertSame(1, $history[0]['waitingPeak']);
        self::assertSame(1, $history[0]['activePeak']);
        self::assertSame(0, $history[0]['unresolvedPeak']);
        self::assertSame(1, $history[0]['oldestWaitSeconds']);
        $target = $store->history(new QueueMetricWindow(24, $at->modify('+4 minutes')), '70000000-0000-4000-8000-000000000001');
        self::assertCount(2, $target);
        self::assertSame(1, $target[0]['waitingPeak']);
        self::assertSame(0, $target[1]['waitingPeak']);
        self::assertSame([], $store->history(new QueueMetricWindow(24, $at->modify('+4 minutes')), '70000000-0000-4000-8000-000000000099'));
    }

    public function testRetentionIsStrictAndEmptyQueueHasRealZeroMeasurements(): void
    {
        $store = new DbalQueueMetricStore($this->connection());
        $at = new DateTimeImmutable('2026-09-08T12:00:00Z');
        $cutoff = $at->modify('-30 days');
        $store->sample($cutoff->modify('-2 minutes'), $cutoff->modify('-30 days'));
        $store->sample($cutoff, $cutoff->modify('-30 days'));
        $store->sample($at, $cutoff);
        $ticks = $this->connection()->fetchOne('SELECT COUNT(*) FROM queue_metric_ticks');
        self::assertTrue(is_int($ticks) || is_string($ticks));
        self::assertSame(2, (int) $ticks);
        $samples = $this->connection()->fetchOne("SELECT COUNT(*) FROM queue_metric_samples WHERE scope_id = UNHEX(REPEAT('00',16))");
        self::assertTrue(is_int($samples) || is_string($samples));
        self::assertSame(2, (int) $samples);
        $history = $store->history(new QueueMetricWindow(720, $at), null);
        self::assertCount(1, $history);
        self::assertSame(0, $history[0]['waitingPeak']);
        self::assertSame(0.0, $history[0]['waitingAverage']);
        self::assertSame(0, $history[0]['oldestWaitSeconds']);
    }

    public function testThirtyDayRawSeriesReturnsBoundedHourlyAggregates(): void
    {
        $at = new DateTimeImmutable('2026-09-08T00:00:00Z');
        $start = $at->modify('-30 days');
        for ($hour = 0; $hour < 720; ++$hour) {
            $times = [];
            $values = [];
            for ($minute = 0; $minute < 60; $minute += 2) {
                $time = $start->modify('+'.($hour * 60 + $minute).' minutes')->format('Y-m-d H:i:s.u');
                $times[] = $time;
                $values[] = $time;
                $values[] = $minute;
            }
            $this->connection()->executeStatement('INSERT INTO queue_metric_ticks (observed_at) VALUES '.implode(',', array_fill(0, 30, '(?)')), $times);
            $this->connection()->executeStatement("INSERT INTO queue_metric_samples (scope_id,observed_at,waiting_count,active_count,unresolved_count,oldest_wait_seconds) VALUES ".implode(',', array_fill(0, 30, "(UNHEX(REPEAT('00',16)),?,?,0,0,0)")), $values);
        }
        $history = (new DbalQueueMetricStore($this->connection()))->history(new QueueMetricWindow(720, $at), null);
        self::assertCount(720, $history);
        self::assertSame('2026-08-09T00:00:00Z', $history[0]['observedAt']);
        self::assertSame('2026-09-07T23:00:00Z', $history[719]['observedAt']);
        self::assertSame(30, $history[719]['samples']);
        self::assertSame(29.0, $history[719]['waitingAverage']);
        self::assertSame(58, $history[719]['waitingPeak']);
    }

    private function seedQaFixture(FrozenClock $clock): void
    {
        $now = '2026-07-13 00:00:00.000000';
        $this->connection()->insert('users', [
            'id' => self::USER, 'username' => 'qa-admin', 'display_name' => 'QA Admin',
            'password_hash' => '$argon2id$fixture', 'enabled' => 1, 'revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        (new MariaDbQaFixtureSeeder($this->connection(), $clock))->seed();
    }

    private function seedPendingRequest(string $id): void
    {
        $resolved = '{"version":2,"mode":"snapshot","compression":"zstd","desiredRetention":{"prune-backups":{"keep-last":7}},"failureNotificationRecipients":[]}';
        $this->connection()->insert('backup_requests', [
            'id' => $id, 'root_request_id' => $id, 'attempt' => 1, 'origin' => 'manual',
            'state' => 'pending', 'reason' => 'manual', 'priority' => 400,
            'scheduled_at' => '2026-07-12 23:59:59.000000', 'available_at' => '2026-07-12 23:59:59.000000',
            'connection_id' => self::uuid('10000000-0000-4000-8000-000000000001'),
            'cluster_id' => self::uuid('30000000-0000-4000-8000-000000000001'),
            'guest_id' => self::uuid('50000000-0000-4000-8000-000000000101'),
            'node_id' => self::uuid('40000000-0000-4000-8000-000000000001'),
            'placement_revision' => 1, 'placement_observed_at' => '2026-07-13 00:00:00.000000',
            'policy_id' => self::uuid('80000000-0000-4000-8000-000000000001'), 'policy_revision' => 1,
            'target_id' => self::uuid('70000000-0000-4000-8000-000000000001'), 'target_revision' => 1,
            'resolved_policy_json' => $resolved, 'resolved_policy_hash' => hash('sha256', $resolved, true),
            'expected_size_bytes' => 1073741824, 'retry_disposition' => 'not_applicable',
            'created_at' => '2026-07-12 23:59:59.000000', 'updated_at' => '2026-07-12 23:59:59.000000',
        ]);
    }

    private static function uuid(string $uuid): string
    {
        $binary = hex2bin(str_replace('-', '', $uuid));
        self::assertIsString($binary);

        return $binary;
    }
}
