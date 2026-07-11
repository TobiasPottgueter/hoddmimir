<?php

declare(strict_types=1);

namespace App\Application\Collector;

use InvalidArgumentException;

final readonly class CollectorWorkerId
{
    public function __construct(public string $bytes)
    {
        if (16 !== strlen($this->bytes)) {
            throw new InvalidArgumentException('A collector worker ID must contain exactly 16 bytes.');
        }
    }

    public function toHex(): string
    {
        return bin2hex($this->bytes);
    }
}
