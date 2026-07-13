<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BackupProblemState
{
    private function __construct(
        public ?BackupProblemCode $code,
        public int $consecutiveFailures,
        public ?DateTimeImmutable $openedAt,
        public ?DateTimeImmutable $lastOccurredAt,
        public ?DateTimeImmutable $lastNotifiedAt,
    ) {
        foreach ([$openedAt, $lastOccurredAt, $lastNotifiedAt] as $timestamp) {
            if (null !== $timestamp && 0 !== $timestamp->getOffset()) {
                throw new InvalidArgumentException('Problem timestamps must use UTC.');
            }
        }
        $open = null !== $code;
        if ($open !== ($consecutiveFailures > 0)
            || $open !== (null !== $openedAt)
            || $open !== (null !== $lastOccurredAt)
            || $open !== (null !== $lastNotifiedAt)
        ) {
            throw new InvalidArgumentException('The backup problem state is inconsistent.');
        }
        if ($open && ($lastOccurredAt < $openedAt || $lastNotifiedAt < $openedAt)) {
            throw new InvalidArgumentException('The backup problem timeline is inconsistent.');
        }
    }

    public static function healthy(): self
    {
        return new self(null, 0, null, null, null);
    }

    public static function open(
        BackupProblemCode $code,
        int $consecutiveFailures,
        DateTimeImmutable $openedAt,
        DateTimeImmutable $lastOccurredAt,
        DateTimeImmutable $lastNotifiedAt,
    ): self {
        return new self($code, $consecutiveFailures, $openedAt, $lastOccurredAt, $lastNotifiedAt);
    }

    public function isOpen(): bool
    {
        return null !== $this->code;
    }
}
