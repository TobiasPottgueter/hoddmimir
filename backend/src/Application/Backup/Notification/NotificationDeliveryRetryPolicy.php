<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class NotificationDeliveryRetryPolicy
{
    private const array DELAYS_SECONDS = [60, 300, 900, 1_800, 3_600];

    public function nextAttemptAt(DateTimeImmutable $failedAt, int $completedDeliveryAttempt): DateTimeImmutable
    {
        if (0 !== $failedAt->getOffset() || $completedDeliveryAttempt < 1) {
            throw new InvalidArgumentException('The notification delivery retry input is invalid.');
        }
        $index = \min($completedDeliveryAttempt - 1, \count(self::DELAYS_SECONDS) - 1);

        return $failedAt->add(new DateInterval('PT'.self::DELAYS_SECONDS[$index].'S'));
    }
}
