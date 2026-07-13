<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Notification;

use App\Application\Backup\Notification\BackupNotificationConfiguration;

use App\Application\Backup\Notification\BackupNotification;
use App\Application\Backup\Notification\BackupNotificationDeliveryStore;
use App\Application\Backup\Notification\BackupNotificationDeliveryGate;
use App\Application\Backup\Notification\BackupNotificationKind;
use App\Application\Backup\Notification\ClaimedBackupNotification;
use App\Application\Backup\Notification\DeliverBackupNotification;
use App\Application\Backup\Notification\MatrixNotificationFormatter;
use App\Application\Backup\Notification\MatrixWebhook;
use App\Application\Backup\Notification\MatrixWebhookFailure;
use App\Application\Backup\Notification\MatrixWebhookFailureCode;
use App\Application\Backup\Notification\NotificationDeliveryRetryPolicy;
use App\Application\Backup\Notification\NotificationDeliveryStatus;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Domain\Backup\BackupProblemCode;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupNotificationTest extends TestCase
{
    public function testBackupExecutionRequiresEnabledAndValidProblemDelivery(): void
    {
        foreach ([
            [false, false, false, true],
            [true, false, true, false],
            [true, true, false, false],
            [true, true, true, true],
        ] as [$execution, $delivery, $configuration, $expected]) {
            $safety = new \App\Application\Backup\Worker\BackupWorkerRuntimeSafety(
                new RuntimeExecutionGate($execution),
                new NotificationDeliveryGateFake($delivery),
                new RuntimeNotificationConfiguration($configuration),
            );
            self::assertSame($expected, $safety->allowsStartup());
        }
    }

    public function testFailureMessageContainsEveryRequiredNonSensitiveField(): void
    {
        $body = (new MatrixNotificationFormatter())->format($this->notification());

        self::assertSame(<<<'TEXT'
Backup fehlgeschlagen – Versuch Nr. 7
VM: customer-dc (VM 202033)
Knoten: otto
Backupziel: rekordo / german
Fehler: task_failed / pve_task_error
Fehlerzeit: 2026-07-13 10:00:00.123456 UTC
Nächster Versuch: 2026-07-13 11:00:00.123456 UTC
TEXT, $body);
        self::assertStringNotContainsString('UPID:', $body);
    }

    public function testRecoveryMessageContainsFailureCountAndNoRetry(): void
    {
        $body = (new MatrixNotificationFormatter())->format($this->notification(BackupNotificationKind::Recovery));

        self::assertStringContainsString('Backup wieder erfolgreich – nach 7 Fehlversuch(en)', $body);
        self::assertStringContainsString('CT: customer-dc (CT 202033)', $body);
        self::assertStringContainsString('Fehlerdauer: 3600 Sekunden', $body);
        self::assertStringContainsString('Erfolgszeit: 2026-07-13 10:00:00.123456 UTC', $body);
        self::assertStringNotContainsString('Nächster Versuch', $body);
    }

    public function testAmbiguousSubmissionCreatesAttentionMessageWithoutRetryPromise(): void
    {
        $notification = $this->notification(
            BackupNotificationKind::AttentionRequired,
            detail: 'ambiguous_submission',
        );
        $body = (new MatrixNotificationFormatter())->format($notification);

        self::assertStringContainsString('Backupzustand unklar – Versuch Nr. 7 erfordert Prüfung', $body);
        self::assertStringContainsString('Es wird kein automatischer vzdump-Retry ausgeführt.', $body);
        self::assertStringNotContainsString('Nächster Versuch:', $body);
    }

    public function testMessagesWithoutOptionalDetailRemainWellFormed(): void
    {
        $failure = (new MatrixNotificationFormatter())->format($this->notification(detail: null));
        self::assertStringContainsString("Fehler: task_failed\n", $failure);
        $attention = (new MatrixNotificationFormatter())->format($this->notification(
            BackupNotificationKind::AttentionRequired,
            detail: null,
        ));
        self::assertStringContainsString("Problem: task_failed\n", $attention);
    }

    public function testFormatterCoversEveryGuestTypeKindAndDetailBranch(): void
    {
        $formatter = new MatrixNotificationFormatter();
        foreach ([PveGuestType::Qemu, PveGuestType::Lxc] as $guestType) {
            foreach ([BackupNotificationKind::Failure, BackupNotificationKind::AttentionRequired, BackupNotificationKind::Recovery] as $kind) {
                foreach ([null, 'task_error'] as $detail) {
                    $body = $formatter->format($this->notification($kind, detail: $detail, guestType: $guestType));
                    self::assertNotSame('', $body);
                }
            }
        }
    }

    public function testFormatterFailsClosedWhenAnInvalidFailureObjectLosesItsRetryTime(): void
    {
        $valid = $this->notification();
        $reflection = new \ReflectionClass(BackupNotification::class);
        /** @var BackupNotification $invalid */
        $invalid = $reflection->newInstanceWithoutConstructor();
        foreach ($reflection->getProperties() as $property) {
            $property->setValue($invalid, 'nextRetryAt' === $property->getName() ? null : $property->getValue($valid));
        }

        $this->expectException(\LogicException::class);
        (new MatrixNotificationFormatter())->format($invalid);
    }

    #[DataProvider('retryProvider')]
    public function testDeliveryRetryContinuesIndefinitelyWithHourlyCap(int $attempt, int $seconds): void
    {
        $at = $this->at('2026-07-13T10:00:00.000000Z');

        self::assertSame(
            $at->getTimestamp() + $seconds,
            (new NotificationDeliveryRetryPolicy())->nextAttemptAt($at, $attempt)->getTimestamp(),
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function retryProvider(): iterable
    {
        yield 'first' => [1, 60];
        yield 'second' => [2, 300];
        yield 'third' => [3, 900];
        yield 'fourth' => [4, 1_800];
        yield 'fifth' => [5, 3_600];
        yield 'hundredth' => [100, 3_600];
    }

    public function testDeliveryClaimsFormatsAndMarksExactlyOneEventSent(): void
    {
        $store = new NotificationStoreFake(new ClaimedBackupNotification($this->notification(), str_repeat('c', 16), 1));
        $webhook = new MatrixWebhookFake();

        $result = $this->deliverer($store, $webhook)->execute($this->at('2026-07-13T10:02:00.000000Z'));

        self::assertSame(NotificationDeliveryStatus::Sent, $result);
        self::assertSame(str_repeat('65', 16), $webhook->eventId);
        self::assertIsString($webhook->body);
        self::assertStringContainsString('Versuch Nr. 7', $webhook->body);
        self::assertSame(1, $store->sent);
        self::assertSame(0, $store->rescheduled);
    }

    public function testWebhookFailureReschedulesPersistedEventWithoutAffectingBackup(): void
    {
        $claimed = new ClaimedBackupNotification($this->notification(), str_repeat('c', 16), 3);
        $store = new NotificationStoreFake($claimed);
        $webhook = new MatrixWebhookFake();
        $webhook->failure = MatrixWebhookFailureCode::Transport;
        $now = $this->at('2026-07-13T10:02:00.000000Z');

        $result = $this->deliverer($store, $webhook)->execute($now);

        self::assertSame(NotificationDeliveryStatus::Rescheduled, $result);
        self::assertSame(0, $store->sent);
        self::assertSame(1, $store->rescheduled);
        self::assertSame(MatrixWebhookFailureCode::Transport, $store->failure);
        self::assertSame('2026-07-13T10:17:00+00:00', $store->nextAttemptAt?->format(DATE_ATOM));
    }

    public function testNoOutboxWorkDoesNotCallWebhook(): void
    {
        $store = new NotificationStoreFake(null);
        $webhook = new MatrixWebhookFake();

        self::assertSame(
            NotificationDeliveryStatus::NoWork,
            $this->deliverer($store, $webhook)->execute($this->at('2026-07-13T10:02:00.000000Z')),
        );
        self::assertSame(0, $webhook->calls);
    }

    public function testWorkerNotificationHookDelegatesOneUtcDeliveryTick(): void
    {
        $store = new NotificationStoreFake(null);
        $hook = new \App\Infrastructure\Notification\ApplicationBackupNotificationDeliveryHook(
            $this->deliverer($store, new MatrixWebhookFake()),
        );

        $hook->deliverOne($this->at('2026-07-13T10:02:00.000000Z'));

        self::assertSame(1, $store->claims);
    }

    public function testDisabledDeliveryDoesNotClaimOrCallWebhook(): void
    {
        $store = new NotificationStoreFake(new ClaimedBackupNotification($this->notification(), str_repeat('c', 16), 1));
        $webhook = new MatrixWebhookFake();

        self::assertSame(
            NotificationDeliveryStatus::NoWork,
            $this->deliverer($store, $webhook, false)->execute($this->at('2026-07-13T10:02:00.000000Z')),
        );
        self::assertSame(0, $store->claims);
        self::assertSame(0, $webhook->calls);
    }

    #[DataProvider('invalidOperationProvider')]
    public function testNotificationContractsRejectInvalidInput(string $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        match ($operation) {
            'event ID' => $this->notification(eventId: 'bad'),
            'attempt' => $this->notification(attempt: 0),
            'guest name' => $this->notification(guestName: ' '),
            'empty guest name' => $this->notification(guestName: ''),
            'guest name length' => $this->notification(guestName: str_repeat('g', 191)),
            'vmid' => $this->notification(vmid: 0),
            'vmid maximum' => $this->notification(vmid: 1_000_000_000),
            'node' => $this->notification(node: ' '),
            'empty node' => $this->notification(node: ''),
            'node length' => $this->notification(node: str_repeat('n', 191)),
            'target' => $this->notification(target: ' '),
            'empty target' => $this->notification(target: ''),
            'target length' => $this->notification(target: str_repeat('t', 191)),
            'detail code' => $this->notification(detail: 'Bad code'),
            'empty detail code' => $this->notification(detail: ''),
            'failure without retry' => $this->notification(nextRetryAt: null, supplyDefaultRetry: false),
            'recovery with retry' => $this->notification(BackupNotificationKind::Recovery, nextRetryAt: $this->at('2026-07-13T11:00:00Z')),
            'non UTC occurrence' => $this->notification(occurredAt: new DateTimeImmutable('2026-07-13T12:00:00+02:00')),
            'non UTC opening' => $this->notification(openedAt: new DateTimeImmutable('2026-07-13T11:00:00+02:00')),
            'opening after occurrence' => $this->notification(openedAt: $this->at('2026-07-13T10:00:01Z')),
            'non UTC retry' => $this->notification(nextRetryAt: new DateTimeImmutable('2026-07-13T13:00:00+02:00')),
            'retry not after failure' => $this->notification(nextRetryAt: $this->at('2026-07-13T10:00:00.123456Z')),
            'failure count' => $this->notification(failures: 0),
            'claim token' => new ClaimedBackupNotification($this->notification(), 'bad', 1),
            'delivery attempt' => new ClaimedBackupNotification($this->notification(), str_repeat('c', 16), 0),
            'retry attempt' => (new NotificationDeliveryRetryPolicy())->nextAttemptAt($this->at('2026-07-13T10:00:00Z'), 0),
            'retry timezone' => (new NotificationDeliveryRetryPolicy())->nextAttemptAt(new DateTimeImmutable('2026-07-13T12:00:00+02:00'), 1),
            'delivery timezone' => $this->deliverer(new NotificationStoreFake(null), new MatrixWebhookFake())
                ->execute(new DateTimeImmutable('2026-07-13T12:00:00+02:00')),
            default => throw new \LogicException('Unknown invalid operation.'),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOperationProvider(): iterable
    {
        foreach ([
            'event ID', 'attempt', 'guest name', 'empty guest name', 'guest name length', 'vmid', 'vmid maximum',
            'node', 'empty node', 'node length', 'target', 'empty target', 'target length', 'detail code', 'empty detail code',
            'failure without retry', 'recovery with retry', 'non UTC occurrence', 'non UTC opening',
            'opening after occurrence', 'non UTC retry',
            'retry not after failure', 'failure count', 'claim token', 'delivery attempt',
            'retry attempt', 'retry timezone', 'delivery timezone',
        ] as $operation) {
            yield $operation => [$operation];
        }
    }

    private function deliverer(
        NotificationStoreFake $store,
        MatrixWebhookFake $webhook,
        bool $enabled = true,
    ): DeliverBackupNotification
    {
        return new DeliverBackupNotification(
            $store,
            $webhook,
            new MatrixNotificationFormatter(),
            new NotificationDeliveryRetryPolicy(),
            new NotificationDeliveryGateFake($enabled),
        );
    }

    private function notification(
        BackupNotificationKind $kind = BackupNotificationKind::Failure,
        string $eventId = 'eeeeeeeeeeeeeeee',
        int $attempt = 7,
        string $guestName = 'customer-dc',
        int $vmid = 202033,
        string $node = 'otto',
        string $target = 'rekordo / german',
        ?string $detail = 'pve_task_error',
        ?DateTimeImmutable $openedAt = null,
        ?DateTimeImmutable $occurredAt = null,
        ?DateTimeImmutable $nextRetryAt = null,
        int $failures = 7,
        bool $supplyDefaultRetry = true,
        ?PveGuestType $guestType = null,
    ): BackupNotification {
        $occurredAt ??= $this->at('2026-07-13T10:00:00.123456Z');
        $openedAt ??= $this->at('2026-07-13T09:00:00.123456Z');
        if ($supplyDefaultRetry && BackupNotificationKind::Failure === $kind && null === $nextRetryAt) {
            $nextRetryAt = $this->at('2026-07-13T11:00:00.123456Z');
        }

        return new BackupNotification(
            $eventId,
            $kind,
            $attempt,
            $guestName,
            $vmid,
            $guestType ?? (BackupNotificationKind::Failure === $kind ? PveGuestType::Qemu : PveGuestType::Lxc),
            $node,
            $target,
            BackupProblemCode::TaskFailed,
            $detail,
            $openedAt,
            $occurredAt,
            $nextRetryAt,
            $failures,
        );
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}

final class NotificationStoreFake implements BackupNotificationDeliveryStore
{
    public int $claims = 0;
    public int $sent = 0;
    public int $rescheduled = 0;
    public ?MatrixWebhookFailureCode $failure = null;
    public ?DateTimeImmutable $nextAttemptAt = null;

    public function __construct(private readonly ?ClaimedBackupNotification $claimed)
    {
    }

    public function claim(DateTimeImmutable $now): ?ClaimedBackupNotification
    {
        ++$this->claims;
        return $this->claimed;
    }

    public function markSent(ClaimedBackupNotification $claimed, DateTimeImmutable $sentAt): void
    {
        ++$this->sent;
    }

    public function reschedule(
        ClaimedBackupNotification $claimed,
        MatrixWebhookFailureCode $failure,
        DateTimeImmutable $failedAt,
        DateTimeImmutable $nextAttemptAt,
    ): void {
        ++$this->rescheduled;
        $this->failure = $failure;
        $this->nextAttemptAt = $nextAttemptAt;
    }
}

final readonly class NotificationDeliveryGateFake implements BackupNotificationDeliveryGate
{
    public function __construct(private bool $value)
    {
    }

    public function enabled(): bool
    {
        return $this->value;
    }
}

final readonly class RuntimeExecutionGate implements \App\Application\Backup\Execution\BackupExecutionGate
{
    public function __construct(private bool $value) {}
    public function enabled(): bool { return $this->value; }
}

final readonly class RuntimeNotificationConfiguration implements BackupNotificationConfiguration
{
    public function __construct(private bool $value) {}
    public function isValid(): bool { return $this->value; }
}

final class MatrixWebhookFake implements MatrixWebhook
{
    public int $calls = 0;
    public ?MatrixWebhookFailureCode $failure = null;
    public ?string $eventId = null;
    public ?string $body = null;

    public function send(string $eventId, string $body): void
    {
        ++$this->calls;
        $this->eventId = $eventId;
        $this->body = $body;
        if (null !== $this->failure) {
            throw new MatrixWebhookFailure($this->failure);
        }
    }
}
