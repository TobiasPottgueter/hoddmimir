<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use InvalidArgumentException;

final readonly class GateSubjectId
{
    public function __construct(private string $bytes)
    {
        if (16 !== strlen($bytes)) {
            throw new InvalidArgumentException('A gate subject ID must contain exactly 16 bytes.');
        }
    }

    public function binary(): string
    {
        return $this->bytes;
    }
}
