<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use InvalidArgumentException;

final readonly class TaskUpid
{
    public function __construct(public string $value)
    {
        $length = strlen($value);
        if (0 === $length) {
            throw new InvalidArgumentException('The task UPID is invalid.');
        }
        if ($length > 4096) {
            throw new InvalidArgumentException('The task UPID is invalid.');
        }
        if ($length < 5 || $value[0].$value[1].$value[2].$value[3].$value[4] !== 'UPID:') {
            throw new InvalidArgumentException('The task UPID is invalid.');
        }
        for ($index = 5; $index < $length; ++$index) {
            if ($value[$index] <= ' ') {
                throw new InvalidArgumentException('The task UPID is invalid.');
            }
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
