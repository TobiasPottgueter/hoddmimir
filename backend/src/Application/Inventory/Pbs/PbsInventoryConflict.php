<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use RuntimeException;

final class PbsInventoryConflict extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $connectionChanged = false,
    ) {
        parent::__construct($message);
    }

    public static function connectionChanged(): self
    {
        return new self('The PBS connection changed or is not enabled.', true);
    }
}
