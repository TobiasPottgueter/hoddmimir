<?php

declare(strict_types=1);

namespace App\Application\Inventory\Capability;

use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final readonly class CapabilitySnapshotObservation
{
    public DateTimeImmutable $observedAt;

    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $connectionId,
        public int $expectedConnectionRevision,
        public EndpointId $endpointId,
        public CapabilityProfile $profile,
        DateTimeImmutable $observedAt,
    ) {
        if ($expectedConnectionRevision < 1) {
            throw new InvalidArgumentException('The capability observation revision is invalid.');
        }
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }

    /** @throws JsonException */
    public function snapshotHash(): string
    {
        $json = json_encode(
            $this->profile->canonicalDocument($this->endpointId->toHex()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        return hash('sha256', $json, true);
    }
}
