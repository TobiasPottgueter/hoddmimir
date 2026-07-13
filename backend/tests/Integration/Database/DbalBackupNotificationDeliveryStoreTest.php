<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Backup\Notification\MatrixWebhookFailureCode;
use App\Application\Backup\Queue\QueueClaimTokenSource;
use App\Infrastructure\Persistence\MariaDb\DbalBackupNotificationDeliveryStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class DbalBackupNotificationDeliveryStoreTest extends DatabaseTestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $value = $this->connection()->fetchOne('SELECT UTC_TIMESTAMP(6)');
        self::assertIsString($value);
        $this->now = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'))
            ?: throw new RuntimeException('Could not read MariaDB UTC time.');
    }

    public function testClaimMapsClosedPayloadAndMarksExactlyThatClaimSent(): void
    {
        $this->insertOutbox();
        $store = $this->store();

        $claimed = $store->claim($this->now);
        self::assertNotNull($claimed);
        self::assertSame(1, $claimed->deliveryAttempt);
        self::assertSame('customer-dc', $claimed->notification->guestName);
        self::assertSame(202033, $claimed->notification->vmid);
        self::assertSame('pve_task_error', $claimed->notification->detailCode);
        self::assertNull($store->claim($this->now), 'An unexpired outbox claim must not be claimed twice.');

        try {
            $store->markSent(
                new \App\Application\Backup\Notification\ClaimedBackupNotification(
                    $claimed->notification,
                    str_repeat('x', 16),
                    1,
                ),
                $this->now,
            );
            self::fail('A stale notification claim was accepted.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
        $store->markSent($claimed, $this->now);
        self::assertSame('sent', $this->connection()->fetchOne('SELECT state FROM backup_notification_outbox'));
        $attempts = $this->connection()->fetchOne('SELECT delivery_attempts FROM backup_notification_outbox');
        self::assertTrue(is_int($attempts) || is_string($attempts));
        self::assertSame('1', (string) $attempts);
        self::assertNull($store->claim($this->now->modify('+1 hour')));
    }

    public function testFailureReschedulesAndLaterClaimIncrementsAttemptIndefinitely(): void
    {
        $this->insertOutbox();
        $store = $this->store();
        $first = $store->claim($this->now);
        self::assertNotNull($first);
        $retryAt = $this->now->modify('+15 minutes');

        $store->reschedule($first, MatrixWebhookFailureCode::Transport, $this->now, $retryAt);
        self::assertSame('pending', $this->connection()->fetchOne('SELECT state FROM backup_notification_outbox'));
        self::assertSame('transport', $this->connection()->fetchOne('SELECT last_error_code FROM backup_notification_outbox'));
        self::assertNull($store->claim($retryAt->modify('-1 microsecond')));
        $second = $store->claim($retryAt);
        self::assertNotNull($second);
        self::assertSame(2, $second->deliveryAttempt);
    }

    public function testExpiredClaimIsTakenOverWithANewOpaqueToken(): void
    {
        $this->insertOutbox();
        $tokens = new NotificationTokenSource();
        $store = new DbalBackupNotificationDeliveryStore($this->connection(), $tokens, 60);
        $first = $store->claim($this->now);
        self::assertNotNull($first);

        $taken = $store->claim($this->now->modify('+60 seconds'));
        self::assertNotNull($taken);
        self::assertSame(2, $taken->deliveryAttempt);
        self::assertNotSame($first->claimToken, $taken->claimToken);
    }

    public function testMalformedPayloadFailsBeforeTheRowIsClaimed(): void
    {
        $this->insertOutbox('{"guestName":"missing-all-closed-fields"}');

        try {
            $this->store()->claim($this->now);
            self::fail('Malformed outbox payload was accepted.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('pending', $this->connection()->fetchOne('SELECT state FROM backup_notification_outbox'));
        $attempts = $this->connection()->fetchOne('SELECT delivery_attempts FROM backup_notification_outbox');
        self::assertTrue(is_int($attempts) || is_string($attempts));
        self::assertSame('0', (string) $attempts);
    }

    public function testPersistenceBoundariesFailClosed(): void
    {
        foreach ([
            fn () => new DbalBackupNotificationDeliveryStore($this->connection(), new NotificationTokenSource(), 0),
            fn () => new DbalBackupNotificationDeliveryStore($this->connection(), new NotificationTokenSource(), 3_601),
            fn () => $this->store()->claim(new DateTimeImmutable('2026-07-13T12:00:00+02:00')),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid notification persistence input was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->insertOutbox();
        $invalidTokenStore = new DbalBackupNotificationDeliveryStore(
            $this->connection(),
            new NotificationTokenSource('bad'),
        );
        $this->expectException(RuntimeException::class);
        $invalidTokenStore->claim($this->now);
    }

    private function store(): DbalBackupNotificationDeliveryStore
    {
        return new DbalBackupNotificationDeliveryStore(
            $this->connection(),
            new NotificationTokenSource(),
            60,
        );
    }

    /** @throws JsonException */
    private function insertOutbox(?string $payload = null): void
    {
        $payload ??= json_encode([
            'guestName' => 'customer-dc',
            'vmid' => 202033,
            'guestType' => 'qemu',
            'node' => 'otto',
            'targetLabel' => 'rekordo / german',
            'problemCode' => 'task_failed',
            'detailCode' => 'pve_task_error',
            'openedAt' => '2026-07-13T09:00:00.123456Z',
            'occurredAt' => '2026-07-13T10:00:00.123456Z',
            'nextRetryAt' => '2026-07-13T11:00:00.123456Z',
            'consecutiveFailures' => 7,
        ], JSON_THROW_ON_ERROR);

        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->connection()->insert('backup_notification_outbox', [
                'id' => self::id('event'),
                'root_request_id' => self::id('root'),
                'request_id' => self::id('request'),
                'run_id' => self::id('run'),
                'notification_kind' => 'failure',
                'event_key' => 'task_failed',
                'attempt' => 7,
                'payload_json' => $payload,
                'state' => 'pending',
                'delivery_attempts' => 0,
                'available_at' => self::format($this->now),
                'created_at' => self::format($this->now),
            ], [
                'id' => ParameterType::BINARY,
                'root_request_id' => ParameterType::BINARY,
                'request_id' => ParameterType::BINARY,
                'run_id' => ParameterType::BINARY,
            ]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function id(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }

    private static function format(DateTimeImmutable $timestamp): string
    {
        return $timestamp->format('Y-m-d H:i:s.u');
    }
}

final class NotificationTokenSource implements QueueClaimTokenSource
{
    private int $sequence = 0;

    public function __construct(private readonly ?string $fixed = null)
    {
    }

    public function next(): string
    {
        if (null !== $this->fixed) {
            return $this->fixed;
        }
        ++$this->sequence;

        return substr(hash('sha256', 'notification-token-'.$this->sequence, true), 0, 16);
    }
}
