<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BackupProblemNotificationPolicy
{
    public function recordFailure(
        BackupProblemState $current,
        BackupProblemCode $code,
        DateTimeImmutable $occurredAt,
    ): BackupProblemNotificationDecision {
        $this->assertUtc($occurredAt);
        if (!$current->isOpen()) {
            $state = BackupProblemState::open($code, 1, $occurredAt, $occurredAt, $occurredAt);

            return new BackupProblemNotificationDecision(
                BackupProblemNotificationAction::Opened,
                $state,
                $code,
                1,
            );
        }
        $openedAt = $current->openedAt ?? throw new InvalidArgumentException('The backup problem is incomplete.');
        $lastOccurredAt = $current->lastOccurredAt ?? throw new InvalidArgumentException('The backup problem is incomplete.');
        $current->lastNotifiedAt ?? throw new InvalidArgumentException('The backup problem is incomplete.');
        if ($occurredAt < $lastOccurredAt) {
            throw new InvalidArgumentException('Backup problem observations must be monotonic.');
        }
        if ($current->consecutiveFailures === PHP_INT_MAX) {
            throw new InvalidArgumentException('The backup problem counter is exhausted.');
        }
        $failures = $current->consecutiveFailures + 1;
        if ($code !== $current->code) {
            $state = BackupProblemState::open($code, $failures, $openedAt, $occurredAt, $occurredAt);

            return new BackupProblemNotificationDecision(
                BackupProblemNotificationAction::Changed,
                $state,
                $code,
                $failures,
            );
        }
        $state = BackupProblemState::open(
            $code,
            $failures,
            $openedAt,
            $occurredAt,
            $occurredAt,
        );

        return new BackupProblemNotificationDecision(
            BackupProblemNotificationAction::RepeatedFailure,
            $state,
            $code,
            $failures,
        );
    }

    public function recordSuccess(
        BackupProblemState $current,
        DateTimeImmutable $succeededAt,
    ): BackupProblemNotificationDecision {
        $this->assertUtc($succeededAt);
        if (!$current->isOpen()) {
            return new BackupProblemNotificationDecision(
                BackupProblemNotificationAction::None,
                $current,
                null,
                0,
            );
        }
        $lastOccurredAt = $current->lastOccurredAt ?? throw new InvalidArgumentException('The backup problem is incomplete.');
        if ($succeededAt < $lastOccurredAt) {
            throw new InvalidArgumentException('A recovery cannot precede the latest failure.');
        }

        return new BackupProblemNotificationDecision(
            BackupProblemNotificationAction::Resolved,
            BackupProblemState::healthy(),
            $current->code,
            $current->consecutiveFailures,
        );
    }

    private function assertUtc(DateTimeImmutable $timestamp): void
    {
        if (0 !== $timestamp->getOffset()) {
            throw new InvalidArgumentException('Backup problem observations must use UTC.');
        }
    }
}
