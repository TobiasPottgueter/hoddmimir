<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorHeartbeatStore;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorWorkerStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;

final readonly class DbalCollectorHeartbeatStore implements CollectorHeartbeatStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(
        CollectorWorkerId $workerId,
        CollectorWorkerStatus $status,
        int $ttlSeconds,
        string $buildVersion,
        ?CollectorCycleToken $cycleToken = null,
        ?DateTimeImmutable $nextActionAt = null,
    ): void {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('The collector heartbeat TTL must be positive.');
        }

        if (1 !== preg_match('/^[\x21-\x7e]{1,64}$/D', $buildVersion)) {
            throw new InvalidArgumentException('The collector build version must be printable ASCII.');
        }

        if ((CollectorWorkerStatus::Busy === $status) !== (null !== $cycleToken)) {
            throw new InvalidArgumentException('Only a busy collector heartbeat carries a cycle token.');
        }

        $databaseNow = $this->databaseNow();
        $formattedNow = $this->format($databaseNow);
        $expiresAt = $this->format($databaseNow->modify(sprintf('+%d seconds', $ttlSeconds)));
        $next = null === $nextActionAt ? null : $this->format($nextActionAt);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO worker_heartbeats (
                    worker_instance_id, worker_kind, status, started_at, heartbeat_at, expires_at,
                    current_activity, current_cycle_token, next_action_at, build_version
                ) VALUES (
                    :worker_id, 'collector', :status, :now, :now, :expires_at,
                    :activity, :cycle_token, :next_action_at, :build_version
                )
                ON DUPLICATE KEY UPDATE
                    worker_kind = VALUES(worker_kind),
                    status = VALUES(status),
                    heartbeat_at = VALUES(heartbeat_at),
                    expires_at = VALUES(expires_at),
                    current_activity = VALUES(current_activity),
                    current_cycle_token = VALUES(current_cycle_token),
                    next_action_at = VALUES(next_action_at),
                    build_version = VALUES(build_version)
                SQL,
            [
                'worker_id' => $workerId->bytes,
                'status' => $status->value,
                'now' => $formattedNow,
                'expires_at' => $expiresAt,
                'activity' => CollectorWorkerStatus::Busy === $status ? 'inventory_cycle' : null,
                'cycle_token' => $cycleToken?->binary(),
                'next_action_at' => $next,
                'build_version' => $buildVersion,
            ],
        );
    }

    public function isFresh(CollectorWorkerId $workerId): bool
    {
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM worker_heartbeats
                WHERE worker_instance_id = :worker_id
                  AND worker_kind = 'collector'
                  AND status IN ('ready', 'busy', 'degraded')
                  AND expires_at > UTC_TIMESTAMP(6)
                SQL,
            ['worker_id' => $workerId->bytes],
        );

        if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
            throw new RuntimeException('MariaDB returned an invalid heartbeat count.');
        }

        return 1 === (int) $count;
    }

    private function databaseNow(): DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT UTC_TIMESTAMP(6)');
        if (!is_string($value)) {
            throw new RuntimeException('MariaDB did not return its UTC clock.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date) {
            throw new RuntimeException('MariaDB returned a malformed UTC clock.');
        }

        return $date;
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
