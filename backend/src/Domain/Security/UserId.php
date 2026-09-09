<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;

final readonly class UserId
{
    public function __construct(private string $bytes)
    {
        if (16 !== strlen($bytes)) {
            throw new InvalidArgumentException('A user ID must contain exactly 16 bytes.');
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
