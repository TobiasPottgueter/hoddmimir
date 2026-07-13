<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Worker\BackupWorkerHeartbeatStatus;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalBackupWorkerHeartbeatStore implements BackupWorkerHeartbeatStore
{
    public function __construct(
        private Connection $connection,
        private int $ttlSeconds,
        private string $buildVersion,
    ) {
        if ($ttlSeconds < 1 || $ttlSeconds > 3600
            || 1 !== preg_match('/^[\x21-\x7e]{1,64}$/D', $buildVersion)) {
            throw new \InvalidArgumentException('Invalid backup worker heartbeat configuration.');
        }
    }

    public function record(string $workerId, BackupWorkerHeartbeatStatus $status, string $activity, DateTimeImmutable $observedAt, DateTimeImmutable $nextActionAt): void
    {
        if (16 !== strlen($workerId) || 0 !== $observedAt->getOffset() || 0 !== $nextActionAt->getOffset()
            || $nextActionAt < $observedAt || 1 !== preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $activity)) {
            throw new \InvalidArgumentException('Invalid backup worker heartbeat.');
        }
        $expiresAt = $observedAt->modify('+'.$this->ttlSeconds.' seconds');
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO worker_heartbeats (
 worker_instance_id,worker_kind,status,started_at,heartbeat_at,expires_at,
 current_activity,current_cycle_token,next_action_at,build_version
) VALUES (:worker,'backup',:status,:now,:now,:expires,:activity,NULL,:next,:build)
ON DUPLICATE KEY UPDATE status=VALUES(status),heartbeat_at=VALUES(heartbeat_at),
 expires_at=VALUES(expires_at),current_activity=VALUES(current_activity),
 current_cycle_token=NULL,next_action_at=VALUES(next_action_at),build_version=VALUES(build_version)
SQL, [
            'worker' => $workerId, 'status' => $status->value, 'now' => self::format($observedAt),
            'expires' => self::format($expiresAt), 'activity' => $activity,
            'next' => self::format($nextActionAt), 'build' => $this->buildVersion,
        ], ['worker' => ParameterType::BINARY]);
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
