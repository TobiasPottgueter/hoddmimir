<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use InvalidArgumentException;

final readonly class PolicyPriority
{
    public function __construct(public int $value)
    {
        if ($value < 0 || $value > 1_000) {
            throw new InvalidArgumentException('A policy tie-break priority must be between zero and one thousand.');
        }
    }
}
