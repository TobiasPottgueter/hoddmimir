<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use InvalidArgumentException;

final readonly class ReasonPriority
{
    public function __construct(
        public BackupReason $reason,
        public Priority $priority,
    ) {
        if (self::expected($reason) !== $priority) {
            throw new InvalidArgumentException('The backup reason and priority do not form a canonical class.');
        }
    }

    public static function expected(BackupReason $reason): Priority
    {
        return Priority::from([
            'manual' => 400,
            'never_backed_up' => 300,
            'max_age' => 200,
            'bytes_written' => 100,
        ][$reason->value]);
    }
}
