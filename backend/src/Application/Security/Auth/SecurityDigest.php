<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use InvalidArgumentException;

final readonly class SecurityDigest
{
    public function __construct(private string $bytes)
    {
        if (32 !== strlen($bytes)) {
            throw new InvalidArgumentException('A security digest must contain exactly 32 bytes.');
        }
    } public function binary(): string
    {
        return $this->bytes;
    } public function equals(self $other): bool
    {
        return hash_equals($this->bytes, $other->bytes);
    }
}
