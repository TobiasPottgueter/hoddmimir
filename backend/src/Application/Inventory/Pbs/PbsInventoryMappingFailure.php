<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use RuntimeException;

final class PbsInventoryMappingFailure extends RuntimeException
{
    public static function invalidSnapshot(): self
    {
        return new self('The PBS installation snapshot cannot be mapped safely.');
    }
}
