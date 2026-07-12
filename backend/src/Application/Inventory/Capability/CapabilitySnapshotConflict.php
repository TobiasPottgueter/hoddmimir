<?php

declare(strict_types=1);

namespace App\Application\Inventory\Capability;

use RuntimeException;

final class CapabilitySnapshotConflict extends RuntimeException
{
    private function __construct(
        public readonly bool $connectionChanged,
        string $message,
        ?\Throwable $previous = null,
    )
    {
        parent::__construct($message, 0, $previous);
    }

    public static function connectionChanged(): self
    {
        return new self(true, 'The capability connection changed.');
    }

    public static function invariant(string $message, ?\Throwable $previous = null): self
    {
        return new self(false, $message, $previous);
    }
}
