<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use RuntimeException;

final class PveCoreInventoryMappingFailure extends RuntimeException
{
    private const array MESSAGES = [
        'non_pve_read' => 'The installation read is not a PVE snapshot.',
        'binding_mismatch' => 'The PVE installation read has an inconsistent binding.',
    ];

    private function __construct(public readonly PveCoreInventoryMappingFailureCode $failureCode)
    {
        parent::__construct(self::MESSAGES[$failureCode->value]);
    }

    public static function nonPveRead(): self
    {
        return new self(PveCoreInventoryMappingFailureCode::NonPveRead);
    }

    public static function bindingMismatch(): self
    {
        return new self(PveCoreInventoryMappingFailureCode::BindingMismatch);
    }
}
