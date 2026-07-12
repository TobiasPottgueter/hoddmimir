<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorClaimDecision;
use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorScheduleConfigurationMismatch;
use App\Application\Collector\CollectorScheduleSnapshot;
use App\Application\Collector\CollectorScheduleStore;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\GridSchedule;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;

final readonly class DbalCollectorScheduleStore implements CollectorScheduleStore
{
    private const string SCHEDULE_NAME = 'inventory';

    public function __construct(private Connection $connection)
    {
    }

    public function bootstrap(int $gridWidthSeconds): CollectorScheduleSnapshot
    {
        $grid = new GridSchedule(new DateTimeImmutable('@0'), $gridWidthSeconds);
        unset($grid);

        return $this->connection->transactional(function (Connection $connection) use ($gridWidthSeconds): CollectorScheduleSnapshot {
            // One idempotent insert makes concurrent cold starts converge on
            // the single persisted anchor without a read-then-insert race.
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO collector_schedule (
                        schedule_name, grid_started_at, interval_seconds, next_scan_at,
                        lease_fencing_token, updated_at
                    ) VALUES (
                        :schedule_name, UTC_TIMESTAMP(6), :interval_seconds, UTC_TIMESTAMP(6),
                        0, UTC_TIMESTAMP(6)
                    )
                    ON DUPLICATE KEY UPDATE schedule_name = VALUES(schedule_name)
                    SQL,
                [
                    'schedule_name' => self::SCHEDULE_NAME,
                    'interval_seconds' => $gridWidthSeconds,
                ],
            );
            $row = $this->requiredScheduleForUpdate($connection);
            // UTC_TIMESTAMP must be sampled after the potentially blocking row
            // lock. Otherwise decisions after a lock wait use stale time.
            $databaseNow = $this->databaseNow($connection);

            $storedWidth = $this->integer($row, 'interval_seconds');
            if ($storedWidth !== $gridWidthSeconds) {
                throw new CollectorScheduleConfigurationMismatch('The configured collector grid width differs from the persisted schedule.');
            }

            return new CollectorScheduleSnapshot(
                new GridSchedule($this->date($row, 'grid_started_at'), $storedWidth),
                $this->date($row, 'next_scan_at'),
                $databaseNow,
            );
        });
    }

    public function claimDue(
        CollectorWorkerId $workerId,
        CollectorCycleToken $cycleToken,
        int $leaseTtlSeconds,
    ): CollectorClaimDecision {
        $this->positiveTtl($leaseTtlSeconds);

        return $this->connection->transactional(function (Connection $connection) use (
            $workerId,
            $cycleToken,
            $leaseTtlSeconds,
        ): CollectorClaimDecision {
            $row = $this->requiredScheduleForUpdate($connection);
            $databaseNow = $this->databaseNow($connection);
            $leaseExpiresAt = $this->nullableDate($row, 'lease_expires_at');

            if (null !== $leaseExpiresAt && $leaseExpiresAt > $databaseNow) {
                return CollectorClaimDecision::waiting($databaseNow, $leaseExpiresAt);
            }

            $scheduledFor = $this->date($row, 'next_scan_at');
            if ($scheduledFor > $databaseNow) {
                return CollectorClaimDecision::waiting($databaseNow, $scheduledFor);
            }

            $oldToken = $row['lease_token'] ?? null;
            if (is_string($oldToken)) {
                $this->abandonExpiredCycle($connection, $oldToken, $databaseNow);

                $grid = new GridSchedule(
                    $this->date($row, 'grid_started_at'),
                    $this->integer($row, 'interval_seconds'),
                );
                $nextScanAt = $grid->nextTickAfter($databaseNow);
                $formattedNow = $this->format($databaseNow);
                $connection->update('collector_schedule', [
                    'next_scan_at' => $this->format($nextScanAt),
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_acquired_at' => null,
                    'lease_expires_at' => null,
                    'last_cycle_finished_at' => $formattedNow,
                    'updated_at' => $formattedNow,
                ], ['schedule_name' => self::SCHEDULE_NAME]);

                return CollectorClaimDecision::waiting($databaseNow, $nextScanAt);
            }

            $previousFence = $this->integer($row, 'lease_fencing_token');
            if (PHP_INT_MAX === $previousFence) {
                throw new RuntimeException('The collector fencing token is exhausted.');
            }

            $fencingToken = $previousFence + 1;
            $expiresAt = $databaseNow->modify(sprintf('+%d seconds', $leaseTtlSeconds));
            $formattedNow = $this->format($databaseNow);
            $connection->insert('collector_cycles', [
                'cycle_token' => $cycleToken->binary(),
                'schedule_name' => self::SCHEDULE_NAME,
                'worker_instance_id' => $workerId->bytes,
                'worker_kind' => 'collector',
                'fencing_token' => $fencingToken,
                'scheduled_for' => $this->format($scheduledFor),
                'started_at' => $formattedNow,
                'heartbeat_at' => $formattedNow,
                'status' => 'running',
            ]);
            $connection->update('collector_schedule', [
                'lease_owner' => $workerId->bytes,
                'lease_token' => $cycleToken->binary(),
                'lease_fencing_token' => $fencingToken,
                'lease_acquired_at' => $formattedNow,
                'lease_expires_at' => $this->format($expiresAt),
                'last_cycle_started_at' => $formattedNow,
                'last_cycle_finished_at' => null,
                'updated_at' => $formattedNow,
            ], ['schedule_name' => self::SCHEDULE_NAME]);

            return CollectorClaimDecision::claimed(
                new CollectorLease($workerId, $cycleToken, $fencingToken, $expiresAt),
                $scheduledFor,
                $databaseNow,
            );
        });
    }

    public function renew(CollectorLease $lease, int $leaseTtlSeconds): CollectorLease
    {
        $this->positiveTtl($leaseTtlSeconds);

        return $this->connection->transactional(function (Connection $connection) use ($lease, $leaseTtlSeconds): CollectorLease {
            $row = $this->requiredScheduleForUpdate($connection);
            $databaseNow = $this->databaseNow($connection);
            $this->assertLeaseRow($row, $lease, $databaseNow);
            $expiresAt = $databaseNow->modify(sprintf('+%d seconds', $leaseTtlSeconds));
            $persistedExpiresAt = $this->nullableDate($row, 'lease_expires_at');
            if (null === $persistedExpiresAt || $expiresAt <= $persistedExpiresAt) {
                throw new InvalidArgumentException('A collector lease renewal must extend its persisted expiry.');
            }
            $formattedNow = $this->format($databaseNow);

            $connection->update('collector_schedule', [
                'lease_expires_at' => $this->format($expiresAt),
                'updated_at' => $formattedNow,
            ], ['schedule_name' => self::SCHEDULE_NAME]);
            $updated = $connection->update('collector_cycles', [
                'heartbeat_at' => $formattedNow,
            ], [
                'cycle_token' => $lease->token->binary(),
                'status' => 'running',
            ]);

            if (1 !== $updated) {
                throw new CollectorLeaseOwnershipLost('The collector cycle is no longer running.');
            }

            return new CollectorLease($lease->ownerId, $lease->token, $lease->fencingToken, $expiresAt);
        });
    }

    public function finalize(
        CollectorLease $lease,
        CollectorCycleStatus $status,
        int $durationMilliseconds,
    ): DateTimeImmutable {
        if ($durationMilliseconds < 0) {
            throw new InvalidArgumentException('A collector cycle duration must not be negative.');
        }
        if (CollectorCycleStatus::Abandoned === $status) {
            throw new InvalidArgumentException('Only an expired-lease takeover may abandon a collector cycle.');
        }

        return $this->connection->transactional(function (Connection $connection) use (
            $lease,
            $status,
            $durationMilliseconds,
        ): DateTimeImmutable {
            $row = $this->requiredScheduleForUpdate($connection);
            $databaseNow = $this->databaseNow($connection);
            $this->assertLeaseRow($row, $lease, $databaseNow);

            $grid = new GridSchedule(
                $this->date($row, 'grid_started_at'),
                $this->integer($row, 'interval_seconds'),
            );
            $nextScanAt = $grid->nextTickAfter($databaseNow);
            $formattedNow = $this->format($databaseNow);
            if (CollectorCycleStatus::Cancelled === $status || CollectorCycleStatus::Failed === $status) {
                $syncStatus = CollectorCycleStatus::Cancelled === $status ? 'cancelled' : 'failed';
                $errorCode = CollectorCycleStatus::Cancelled === $status ? 'collector_stopped' : 'collector_cycle_failed';
                $monitoringErrorCode = CollectorCycleStatus::Cancelled === $status
                    ? 'collector_shutdown_requested' : 'collector_cycle_failed';
                $errorSummary = CollectorCycleStatus::Cancelled === $status
                    ? 'Collector stopped at a safe boundary.'
                    : 'Collector cycle failed before the sync run completed.';
                $connection->executeStatement(
                    <<<'SQL'
                        UPDATE proxmox_monitoring_runs
                        SET status = 'failed', heartbeat_at = :now, finished_at = :now,
                            error_code = :error_code
                        WHERE cycle_token = :cycle_token AND status = 'running' AND applied_at IS NULL
                        SQL,
                    [
                        'now' => $formattedNow,
                        'error_code' => $monitoringErrorCode,
                        'cycle_token' => $lease->token->binary(),
                    ],
                );
                $connection->executeStatement(
                    <<<'SQL'
                        UPDATE pbs_content_runs
                        SET status = 'failed', heartbeat_at = :now, finished_at = :now,
                            error_code = :error_code
                        WHERE cycle_token = :cycle_token AND status = 'running' AND applied_at IS NULL
                        SQL,
                    [
                        'now' => $formattedNow,
                        'error_code' => $monitoringErrorCode,
                        'cycle_token' => $lease->token->binary(),
                    ],
                );
                $connection->executeStatement(
                    <<<'SQL'
                        UPDATE inventory_sync_runs
                        SET status = :status, authoritative = 0, heartbeat_at = :now, finished_at = :now,
                            error_code = :error_code, error_summary = :error_summary
                        WHERE cycle_token = :cycle_token AND status = 'running'
                        SQL,
                    [
                        'status' => $syncStatus,
                        'now' => $formattedNow,
                        'error_code' => $errorCode,
                        'error_summary' => $errorSummary,
                        'cycle_token' => $lease->token->binary(),
                    ],
                );
            }

            $runningSyncRuns = $connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM inventory_sync_runs
                    WHERE cycle_token = :cycle_token AND status = 'running'
                    SQL,
                ['cycle_token' => $lease->token->binary()],
            );
            if (!is_int($runningSyncRuns) && !(is_string($runningSyncRuns) && ctype_digit($runningSyncRuns))) {
                throw new RuntimeException('MariaDB returned an invalid running sync count.');
            }
            if (0 !== (int) $runningSyncRuns) {
                throw new RuntimeException('A collector cycle cannot finish while inventory sync runs are still running.');
            }
            $runningMonitoringRuns = $connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM proxmox_monitoring_runs
                    WHERE cycle_token = :cycle_token AND status = 'running'
                    SQL,
                ['cycle_token' => $lease->token->binary()],
            );
            if (!is_int($runningMonitoringRuns)
                && !(is_string($runningMonitoringRuns) && ctype_digit($runningMonitoringRuns))) {
                throw new RuntimeException('MariaDB returned an invalid running monitoring count.');
            }
            if (0 !== (int) $runningMonitoringRuns) {
                throw new RuntimeException('A collector cycle cannot finish while monitoring runs are still running.');
            }
            $runningPbsContentRuns = $connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM pbs_content_runs
                    WHERE cycle_token = :cycle_token AND status = 'running'
                    SQL,
                ['cycle_token' => $lease->token->binary()],
            );
            if (!is_int($runningPbsContentRuns)
                && !(is_string($runningPbsContentRuns) && ctype_digit($runningPbsContentRuns))) {
                throw new RuntimeException('MariaDB returned an invalid running PBS content count.');
            }
            if (0 !== (int) $runningPbsContentRuns) {
                throw new RuntimeException('A collector cycle cannot finish while PBS content runs are still running.');
            }

            $updated = $connection->update('collector_cycles', [
                'heartbeat_at' => $formattedNow,
                'finished_at' => $formattedNow,
                'duration_ms' => $durationMilliseconds,
                'status' => $status->value,
            ], [
                'cycle_token' => $lease->token->binary(),
                'status' => 'running',
            ]);

            if (1 !== $updated) {
                throw new CollectorLeaseOwnershipLost('The collector cycle is no longer running.');
            }

            $connection->update('collector_schedule', [
                'next_scan_at' => $this->format($nextScanAt),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_acquired_at' => null,
                'lease_expires_at' => null,
                'last_cycle_finished_at' => $formattedNow,
                'updated_at' => $formattedNow,
            ], ['schedule_name' => self::SCHEDULE_NAME]);

            return $nextScanAt;
        });
    }

    private function abandonExpiredCycle(
        Connection $connection,
        string $cycleToken,
        DateTimeImmutable $databaseNow,
    ): void {
        $formattedNow = $this->format($databaseNow);
        $connection->executeStatement(
            <<<'SQL'
                UPDATE proxmox_monitoring_runs
                SET status = 'failed', heartbeat_at = :now, finished_at = :now,
                    error_code = 'collector_lease_lost'
                WHERE cycle_token = :cycle_token AND status = 'running' AND applied_at IS NULL
                SQL,
            ['now' => $formattedNow, 'cycle_token' => $cycleToken],
        );
        $connection->executeStatement(
            <<<'SQL'
                UPDATE pbs_content_runs
                SET status = 'failed', heartbeat_at = :now, finished_at = :now,
                    error_code = 'collector_lease_lost'
                WHERE cycle_token = :cycle_token AND status = 'running' AND applied_at IS NULL
                SQL,
            ['now' => $formattedNow, 'cycle_token' => $cycleToken],
        );
        $connection->executeStatement(
            <<<'SQL'
                UPDATE inventory_sync_runs
                SET status = 'abandoned', authoritative = 0, heartbeat_at = :now, finished_at = :now,
                    error_code = 'collector_lease_expired', error_summary = 'Collector lease expired before completion.'
                WHERE cycle_token = :cycle_token AND status = 'running'
                SQL,
            ['now' => $formattedNow, 'cycle_token' => $cycleToken],
        );
        $connection->executeStatement(
            <<<'SQL'
                UPDATE collector_cycles
                SET status = 'abandoned', heartbeat_at = :now, finished_at = :now
                WHERE cycle_token = :cycle_token AND status = 'running'
                SQL,
            ['now' => $formattedNow, 'cycle_token' => $cycleToken],
        );
    }

    /** @return array<string, mixed> */
    private function requiredScheduleForUpdate(Connection $connection): array
    {
        $row = $this->scheduleForUpdate($connection);

        if (false === $row) {
            throw new RuntimeException('The collector schedule has not been initialized.');
        }

        return $row;
    }

    /** @return array<string, mixed>|false */
    private function scheduleForUpdate(Connection $connection): array|false
    {
        return $connection->fetchAssociative(
            'SELECT * FROM collector_schedule WHERE schedule_name = :schedule_name FOR UPDATE',
            ['schedule_name' => self::SCHEDULE_NAME],
        );
    }

    /** @param array<string, mixed> $row */
    private function assertLeaseRow(array $row, CollectorLease $lease, DateTimeImmutable $databaseNow): void
    {
        $owner = $row['lease_owner'] ?? null;
        $token = $row['lease_token'] ?? null;
        $expiresAt = $this->nullableDate($row, 'lease_expires_at');

        if (
            !is_string($owner)
            || !hash_equals($lease->ownerId->bytes, $owner)
            || !is_string($token)
            || !hash_equals($lease->token->binary(), $token)
            || $this->integer($row, 'lease_fencing_token') !== $lease->fencingToken
            || null === $expiresAt
            || $expiresAt <= $databaseNow
        ) {
            throw new CollectorLeaseOwnershipLost('The collector lease is no longer owned by this worker.');
        }
    }

    private function databaseNow(Connection $connection): DateTimeImmutable
    {
        $value = $connection->fetchOne('SELECT UTC_TIMESTAMP(6)');

        if (!is_string($value)) {
            throw new RuntimeException('MariaDB did not return its UTC clock.');
        }

        return $this->parseDate($value);
    }

    /** @param array<string, mixed> $row */
    private function date(array $row, string $key): DateTimeImmutable
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid collector timestamp.');
        }

        return $this->parseDate($value);
    }

    /** @param array<string, mixed> $row */
    private function nullableDate(array $row, string $key): ?DateTimeImmutable
    {
        $value = $row[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException('MariaDB returned an invalid optional collector timestamp.');
        }

        return $this->parseDate($value);
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));

        if (false === $date) {
            throw new RuntimeException('MariaDB returned a malformed collector timestamp.');
        }

        return $date;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException('MariaDB returned an invalid collector integer.');
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function positiveTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('The collector lease TTL must be positive.');
        }
    }
}
