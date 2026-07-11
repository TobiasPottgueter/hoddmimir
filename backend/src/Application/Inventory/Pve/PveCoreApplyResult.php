<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use InvalidArgumentException;

final readonly class PveCoreApplyResult
{
    /** @var 'succeeded'|'partial'|'failed' */
    public string $status;

    public function __construct(
        PveCoreApplyStatus $status,
        public int $created,
        public int $updated,
        public int $archived,
        public bool $diagnosticOnly,
    ) {
        if (min($this->created, $this->updated, $this->archived) < 0) {
            throw new InvalidArgumentException('PVE core apply counters must not be negative.');
        }
        $this->status = $status->value;
    }
}
