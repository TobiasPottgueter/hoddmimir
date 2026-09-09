<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use InvalidArgumentException;

final readonly class PolicyRevision
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('A policy revision must be positive.');
        }
    }

    public function next(): self
    {
        if (PHP_INT_MAX === $this->value) {
            throw new InvalidArgumentException('A policy revision cannot exceed the platform integer range.');
        }

        return new self($this->value + 1);
    }
}
