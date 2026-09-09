<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\InstallationBindingKind;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PbsContentRunStart
{
    public DateTimeImmutable $startedAt;

    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $parentRunId,
        public InventoryIdentifier $connectionId,
        public EndpointId $endpointId,
        public InstallationBinding $binding,
        public int $expectedConnectionRevision,
        DateTimeImmutable $startedAt,
    ) {
        if ($expectedConnectionRevision < 1 || ProxmoxProduct::Pbs !== $binding->product
            || InstallationBindingKind::PbsLegacyNode === $binding->kind
                && $binding->legacyEndpointId?->bytes !== $endpointId->bytes) {
            throw new InvalidArgumentException('The PBS content run header is invalid.');
        }
        $this->startedAt = $startedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
