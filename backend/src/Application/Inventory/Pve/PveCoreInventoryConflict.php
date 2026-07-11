<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use RuntimeException;

final class PveCoreInventoryConflict extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly PveCoreInventoryConflictCode $failureCode = PveCoreInventoryConflictCode::InvariantViolation,
    ) {
        parent::__construct($message);
    }

    public static function connectionChanged(): self
    {
        return new self(
            'The PVE connection changed or is not enabled.',
            PveCoreInventoryConflictCode::ConnectionChanged,
        );
    }
}
