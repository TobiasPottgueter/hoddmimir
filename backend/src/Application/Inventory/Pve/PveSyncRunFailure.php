<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PveSyncRunFailure
{
    public DateTimeImmutable $finishedAt;

    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $connectionId,
        public int $expectedConnectionRevision,
        public ConnectionReadFailureCode $failureCode,
        DateTimeImmutable $finishedAt,
    ) {
        if ($this->expectedConnectionRevision < 1) {
            throw new InvalidArgumentException('The expected connection revision must be positive.');
        }
        $this->finishedAt = $finishedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
