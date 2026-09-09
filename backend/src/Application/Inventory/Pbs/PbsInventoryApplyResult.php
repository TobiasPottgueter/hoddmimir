<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use InvalidArgumentException;

final readonly class PbsInventoryApplyResult
{
    /** @var 'succeeded'|'partial'|'failed' */
    public string $status;

    public function __construct(
        PbsInventoryApplyStatus $status,
        public int $created,
        public int $updated,
        public int $archived,
        public bool $diagnosticOnly,
    ) {
        if (min($created, $updated, $archived) < 0) {
            throw new InvalidArgumentException('PBS inventory apply counters must not be negative.');
        }
        $this->status = $status->value;
    }
}
