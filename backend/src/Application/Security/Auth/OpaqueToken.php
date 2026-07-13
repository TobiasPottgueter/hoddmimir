<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class OpaqueToken
{
    public function __construct(#[SensitiveParameter] private string $bytes)
    {
        if (32 !== strlen($bytes)) {
            throw new InvalidArgumentException('An opaque token must contain exactly 32 bytes.');
        }
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }
}
