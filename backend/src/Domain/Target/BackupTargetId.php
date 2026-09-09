<?php

declare(strict_types=1);

namespace App\Domain\Target;

use InvalidArgumentException;

final readonly class BackupTargetId
{
    public function __construct(private string $bytes)
    {
        if (16 !== strlen($this->bytes)) {
            throw new InvalidArgumentException('A backup-target ID must contain exactly 16 bytes.');
        }
    }

    public function binary(): string
    {
        return $this->bytes;
    }

    public function toHex(): string
    {
        return bin2hex($this->bytes);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->bytes, $other->bytes);
    }
}
