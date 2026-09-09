<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use InvalidArgumentException;

final readonly class InventoryIdentifier
{
    public function __construct(private string $bytes)
    {
        if (16 !== strlen($this->bytes)) {
            throw new InvalidArgumentException('An inventory identifier must contain exactly 16 bytes.');
        }
    }

    public function binary(): string
    {
        return $this->bytes;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->bytes, $other->bytes);
    }
}
