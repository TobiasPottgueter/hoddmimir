<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use InvalidArgumentException;

final readonly class ConnectionId
{
    public function __construct(public string $bytes)
    {
        if (16 !== strlen($bytes)) {
            throw new InvalidArgumentException('A connection ID must contain exactly 16 bytes.');
        }
    }

    public function toHex(): string
    {
        return bin2hex($this->bytes);
    }
}
