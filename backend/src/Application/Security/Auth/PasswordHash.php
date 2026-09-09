<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use InvalidArgumentException;

final readonly class PasswordHash
{
    public function __construct(private string $encoded)
    {
        if (\strlen($encoded) > 255) {
            throw new InvalidArgumentException('The password hash is not an Argon2id encoding.');
        }
        if (!\str_starts_with($encoded, '$argon2id$')) {
            throw new InvalidArgumentException('The password hash is not an Argon2id encoding.');
        }
    }
    public function encoded(): string
    {
        return $this->encoded;
    }
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }
}
