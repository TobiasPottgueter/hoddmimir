<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ShadowPlacementEvidence
{
    public DateTimeImmutable $observedAt;

    public function __construct(
        public InventoryIdentifier $nodeId,
        public int $revision,
        DateTimeImmutable $observedAt,
    ) {
        if ($this->revision < 1) {
            throw new InvalidArgumentException('A shadow placement revision must be positive.');
        }
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
