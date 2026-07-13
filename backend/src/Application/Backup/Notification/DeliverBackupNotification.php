<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliverBackupNotification
{
    public function __construct(
        private BackupNotificationDeliveryStore $store,
        private MatrixWebhook $webhook,
        private MatrixNotificationFormatter $formatter,
        private NotificationDeliveryRetryPolicy $retryPolicy,
        private BackupNotificationDeliveryGate $deliveryGate,
    ) {
    }

    public function execute(DateTimeImmutable $now): NotificationDeliveryStatus
    {
        if (0 !== $now->getOffset()) {
            throw new InvalidArgumentException('Notification delivery must use UTC.');
        }
        if (!$this->deliveryGate->enabled()) {
            return NotificationDeliveryStatus::NoWork;
        }
        $claimed = $this->store->claim($now);
        if (null === $claimed) {
            return NotificationDeliveryStatus::NoWork;
        }

        try {
            $this->webhook->send(
                bin2hex($claimed->notification->eventId),
                $this->formatter->format($claimed->notification),
            );
            $this->store->markSent($claimed, $now);

            return NotificationDeliveryStatus::Sent;
        } catch (MatrixWebhookFailure $failure) {
            $this->store->reschedule(
                $claimed,
                $failure->failureCode,
                $now,
                $this->retryPolicy->nextAttemptAt($now, $claimed->deliveryAttempt),
            );

            return NotificationDeliveryStatus::Rescheduled;
        }
    }
}
