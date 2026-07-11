<?php

declare(strict_types=1);

namespace App\Application\Collector;

use InvalidArgumentException;
use LogicException;

final readonly class CollectorCycleToken
{
    public function __construct(private string $bytes)
    {
        if (16 !== strlen($this->bytes)) {
            throw new InvalidArgumentException('A collector cycle token must contain exactly 16 bytes.');
        }
    }

    public function binary(): string
    {
        return $this->bytes;
    }

    /** @return array{token: string} */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Collector cycle tokens cannot be serialized.');
    }
}
