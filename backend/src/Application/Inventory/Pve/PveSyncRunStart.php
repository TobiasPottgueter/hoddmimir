<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PveSyncRunStart
{
    public DateTimeImmutable $startedAt;

    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $connectionId,
        public int $expectedConnectionRevision,
        DateTimeImmutable $startedAt,
    ) {
        if ($this->expectedConnectionRevision < 1) {
            throw new InvalidArgumentException('The expected connection revision must be positive.');
        }
        $this->startedAt = $startedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
