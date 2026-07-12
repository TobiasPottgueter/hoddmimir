<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\InstallationBindingKind;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class MonitoringRunStart
{
    public DateTimeImmutable $startedAt;

    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $parentRunId,
        public InventoryIdentifier $connectionId,
        public EndpointId $endpointId,
        public ProxmoxProduct $product,
        public InstallationBinding $binding,
        public MonitoringRunKind $kind,
        public int $expectedConnectionRevision,
        DateTimeImmutable $startedAt,
    ) {
        if ($this->expectedConnectionRevision < 1) {
            throw new InvalidArgumentException('The monitoring connection revision must be positive.');
        }
        if ($this->binding->product !== $this->product) {
            throw new InvalidArgumentException('The monitoring binding product must match the connection product.');
        }
        if (InstallationBindingKind::PbsLegacyNode === $this->binding->kind
            && $this->binding->legacyEndpointId?->bytes !== $this->endpointId->bytes) {
            throw new InvalidArgumentException('Legacy PBS monitoring must use the binding endpoint.');
        }
        $this->startedAt = $startedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
