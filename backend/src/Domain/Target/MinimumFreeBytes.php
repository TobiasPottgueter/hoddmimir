<?php

declare(strict_types=1);

namespace App\Domain\Target;

use App\Domain\Shared\UInt64Decimal;

final readonly class MinimumFreeBytes
{
    public UInt64Decimal $bytes;

    public function __construct(string $bytes)
    {
        $this->bytes = new UInt64Decimal($bytes);
    }

    public function decimal(): string
    {
        return $this->bytes->value;
    }
}
