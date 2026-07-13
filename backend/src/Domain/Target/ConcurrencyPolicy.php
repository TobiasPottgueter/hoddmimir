<?php

declare(strict_types=1);

namespace App\Domain\Target;

use InvalidArgumentException;

final readonly class ConcurrencyPolicy
{
    public function __construct(public int $fixedParallelLimit)
    {
        if ($fixedParallelLimit < 1 || $fixedParallelLimit > 100) {
            throw new InvalidArgumentException('The fixed target parallel limit must be between 1 and 100.');
        }
    }
}
