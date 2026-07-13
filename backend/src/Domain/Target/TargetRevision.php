<?php

declare(strict_types=1);

namespace App\Domain\Target;

use InvalidArgumentException;
use OverflowException;

final readonly class TargetRevision
{
    public function __construct(public int $value)
    {
        if ($this->value < 1) {
            throw new InvalidArgumentException('A backup-target revision must be positive.');
        }
    }

    public function next(): self
    {
        if (PHP_INT_MAX === $this->value) {
            throw new OverflowException('The backup-target revision is exhausted.');
        }

        return new self($this->value + 1);
    }
}
