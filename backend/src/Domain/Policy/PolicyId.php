<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use InvalidArgumentException;

final readonly class PolicyId
{
    public function __construct(private string $bytes)
    {
        if (16 !== strlen($bytes)) {
            throw new InvalidArgumentException('A policy ID must contain exactly 16 bytes.');
        }
    }

    public function binary(): string
    {
        return $this->bytes;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->bytes, $other->bytes);
    }
}
