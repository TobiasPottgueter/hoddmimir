<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use InvalidArgumentException;
use OverflowException;

final readonly class ClaimFence
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('A claim fence must be positive.');
        }
    }

    public function next(): self
    {
        if (PHP_INT_MAX === $this->value) {
            throw new OverflowException('A claim fence cannot exceed the platform integer range.');
        }

        return new self($this->value + 1);
    }

    public function isAfter(?self $other): bool
    {
        return null === $other || $this->value > $other->value;
    }
}
