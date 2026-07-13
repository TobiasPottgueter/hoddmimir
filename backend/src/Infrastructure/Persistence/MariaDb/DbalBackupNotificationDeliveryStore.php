<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Notification\BackupNotification;
use App\Application\Backup\Notification\BackupNotificationDeliveryStore;
use App\Application\Backup\Notification\BackupNotificationKind;
use App\Application\Backup\Notification\ClaimedBackupNotification;
use App\Application\Backup\Notification\MatrixWebhookFailureCode;
use App\Application\Backup\Queue\QueueClaimTokenSource;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Domain\Backup\BackupProblemCode;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use JsonException;
use RuntimeException;

final readonly class DbalBackupNotificationDeliveryStore implements BackupNotificationDeliveryStore
{
    public function __construct(
        private Connection $connection,
        private QueueClaimTokenSource $tokens,
        private int $leaseSeconds = 60,
    ) {
        if ($leaseSeconds < 1 || $leaseSeconds > 3_600) {
            throw new \InvalidArgumentException('The notification delivery lease is invalid.');
        }
    }

    public function claim(DateTimeImmutable $now): ?ClaimedBackupNotification
    {
        $this->assertUtc($now);

        return $this->connection->transactional(function (Connection $db) use ($now): ?ClaimedBackupNotification {
            $row = $db->fetchAssociative(<<<'SQL'
SELECT *
FROM backup_notification_outbox
WHERE (state = 'pending' AND available_at <= :now)
   OR (state = 'claimed' AND lease_expires_at <= :now)
ORDER BY available_at, id
LIMIT 1
FOR UPDATE SKIP LOCKED
SQL, ['now' => self::format($now)]);
            if (false === $row) {
                return null;
            }

            $notification = $this->notification($row);
            $attempts = $this->integer($row['delivery_attempts'] ?? null);
            if ($attempts >= 4_294_967_295) {
                throw new RuntimeException('The notification delivery attempt counter is exhausted.');
            }
            ++$attempts;
            $token = $this->tokens->next();
            if (16 !== strlen($token)) {
                throw new RuntimeException('The notification claim token source returned an invalid token.');
            }
            $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_notification_outbox
SET state = 'claimed', delivery_attempts = :attempts, claim_token = :token,
    claimed_at = :now, lease_expires_at = :expires, revision = revision + 1
WHERE id = :id AND revision = :revision
SQL, [
                'attempts' => $attempts,
                'token' => $token,
                'now' => self::format($now),
                'expires' => self::format($now->add(new DateInterval('PT'.$this->leaseSeconds.'S'))),
                'id' => $this->binary($row['id'] ?? null),
                'revision' => $this->integer($row['revision'] ?? null),
            ], ['token' => ParameterType::BINARY, 'id' => ParameterType::BINARY]);
            if (1 !== $affected) {
                throw new RuntimeException('The notification outbox claim changed while locked.');
            }

            return new ClaimedBackupNotification($notification, $token, $attempts);
        });
    }

    public function markSent(ClaimedBackupNotification $claimed, DateTimeImmutable $sentAt): void
    {
        $this->assertUtc($sentAt);
        $affected = $this->connection->executeStatement(<<<'SQL'
UPDATE backup_notification_outbox
SET state = 'sent', claim_token = NULL, claimed_at = NULL, lease_expires_at = NULL,
    sent_at = :sent, last_error_code = NULL, revision = revision + 1
WHERE id = :id AND state = 'claimed' AND claim_token = :token
SQL, [
            'sent' => self::format($sentAt),
            'id' => $claimed->notification->eventId,
            'token' => $claimed->claimToken,
        ], ['id' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
        if (1 !== $affected) {
            throw new RuntimeException('A stale notification claim cannot be marked sent.');
        }
    }

    public function reschedule(
        ClaimedBackupNotification $claimed,
        MatrixWebhookFailureCode $failure,
        DateTimeImmutable $failedAt,
        DateTimeImmutable $nextAttemptAt,
    ): void {
        $this->assertUtc($failedAt);
        $this->assertUtc($nextAttemptAt);
        if ($nextAttemptAt <= $failedAt) {
            throw new \InvalidArgumentException('A notification retry must be scheduled in the future.');
        }
        $affected = $this->connection->executeStatement(<<<'SQL'
UPDATE backup_notification_outbox
SET state = 'pending', available_at = :available, claim_token = NULL, claimed_at = NULL,
    lease_expires_at = NULL, last_error_code = :failure, revision = revision + 1
WHERE id = :id AND state = 'claimed' AND claim_token = :token
SQL, [
            'available' => self::format($nextAttemptAt),
            'failure' => $failure->value,
            'id' => $claimed->notification->eventId,
            'token' => $claimed->claimToken,
        ], ['id' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
        if (1 !== $affected) {
            throw new RuntimeException('A stale notification claim cannot be rescheduled.');
        }
    }

    /** @param array<string, mixed> $row */
    private function notification(array $row): BackupNotification
    {
        $raw = $row['payload_json'] ?? null;
        if (!is_string($raw)) {
            throw new RuntimeException('The notification outbox payload is missing.');
        }
        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The notification outbox payload is invalid.', 0, $failure);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('The notification outbox payload is invalid.');
        }
        $expected = [
            'consecutiveFailures', 'detailCode', 'guestName', 'guestType', 'nextRetryAt',
            'node', 'occurredAt', 'openedAt', 'problemCode', 'targetLabel', 'vmid',
        ];
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== $expected) {
            throw new RuntimeException('The notification outbox payload has an unknown shape.');
        }

        return new BackupNotification(
            $this->binary($row['id'] ?? null),
            BackupNotificationKind::from($this->text($row['notification_kind'] ?? null)),
            $this->integer($row['attempt'] ?? null),
            $this->text($payload['guestName']),
            $this->integer($payload['vmid']),
            PveGuestType::from($this->text($payload['guestType'])),
            $this->text($payload['node']),
            $this->text($payload['targetLabel']),
            BackupProblemCode::from($this->text($payload['problemCode'])),
            $this->nullableText($payload['detailCode']),
            $this->timestamp($payload['openedAt']),
            $this->timestamp($payload['occurredAt']),
            null === $payload['nextRetryAt'] ? null : $this->timestamp($payload['nextRetryAt']),
            $this->integer($payload['consecutiveFailures']),
        );
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new RuntimeException('The notification timestamp is invalid.');
        }
        $timestamp = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        if (false === $timestamp || $timestamp->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new RuntimeException('The notification timestamp is invalid.');
        }

        return $timestamp;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (false !== $integer) {
                return $integer;
            }
        }
        throw new RuntimeException('The notification integer is invalid.');
    }

    private function binary(mixed $value): string
    {
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('The notification binary identifier is invalid.');
        }

        return $value;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('The notification text value is invalid.');
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return $this->text($value);
    }

    private function assertUtc(DateTimeImmutable $timestamp): void
    {
        if (0 !== $timestamp->getOffset()) {
            throw new \InvalidArgumentException('Notification persistence requires UTC.');
        }
    }

    private static function format(DateTimeImmutable $timestamp): string
    {
        return $timestamp->format('Y-m-d H:i:s.u');
    }
}
