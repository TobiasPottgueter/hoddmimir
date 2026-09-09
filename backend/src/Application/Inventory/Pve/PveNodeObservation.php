<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use InvalidArgumentException;

final readonly class PveNodeObservation
{
    public function __construct(
        public string $name,
        public string $apiStatus,
    ) {
        if (!PveCoreTextValidator::isNodeName($this->name)) {
            throw new InvalidArgumentException('The PVE node name is invalid.');
        }
        if ('online' !== $this->apiStatus
            && 'offline' !== $this->apiStatus
            && 'unknown' !== $this->apiStatus) {
            throw new InvalidArgumentException('The PVE node API status is invalid.');
        }
    }
}
